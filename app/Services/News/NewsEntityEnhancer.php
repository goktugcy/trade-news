<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\AiTask;
use App\Models\NewsItem;
use App\Models\Stock;
use App\Services\Ai\AiTaskService;
use App\Services\Ai\EmbeddingService;
use App\Services\Ai\HuggingFaceEndpointClient;
use Illuminate\Support\Facades\Log;

/**
 * Optional enhancement layer on top of the deterministic NewsMatcherService.
 * Runs only when the entity/embedding tasks are enabled; never breaks the
 * deterministic matches that already ran.
 */
class NewsEntityEnhancer
{
    public function __construct(
        private readonly AiTaskService $tasks,
        private readonly EmbeddingService $embeddings,
    ) {}

    public function isEnabled(NewsItem $item): bool
    {
        return $this->tasks->isEnabled($this->taskForItem($item));
    }

    /**
     * Extract named entities and link any that resolve to a known stock not yet
     * matched. Returns the number of new matches added.
     */
    public function enhance(NewsItem $item): int
    {
        try {
            $task = $this->taskForItem($item);
            $model = $this->tasks->modelFor($task);
            $client = $model !== null ? $this->tasks->huggingFaceFor($model) : null;

            if ($model === null || ! $client instanceof HuggingFaceEndpointClient) {
                return 0;
            }

            $text = trim(implode('. ', array_filter([$item->title, $item->summary])));

            if ($text === '') {
                return 0;
            }

            $result = $client->tokenClassify($model, $text);

            if (! $result->successful || $result->entities === null) {
                $this->tasks->recordFailure($model, $result->error, $result->latencyMs);

                return 0;
            }

            $this->tasks->recordSuccess($model, $result->latencyMs);

            return $this->linkEntities($item, $result->entities);
        } catch (\Throwable $e) {
            Log::warning('Entity enhancement failed', ['news_item' => $item->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $entities
     */
    private function linkEntities(NewsItem $item, array $entities): int
    {
        $existing = $item->matches()->pluck('stock_id')->all();
        $added = 0;

        $words = collect($entities)
            ->filter(fn (array $e): bool => str_contains(mb_strtolower((string) ($e['entity_group'] ?? '')), 'org'))
            ->map(fn (array $e): string => trim((string) ($e['word'] ?? '')))
            ->filter(fn (string $w): bool => mb_strlen($w) >= 3)
            ->unique()
            ->values();

        foreach ($words as $word) {
            $stock = Stock::query()->active()
                ->whereNotIn('id', $existing)
                ->where(fn ($q) => $q->where('name', 'ILIKE', "%{$word}%")->orWhere('symbol', 'ILIKE', $word))
                ->first();

            if ($stock === null) {
                continue;
            }

            $item->matches()->updateOrCreate(
                ['stock_id' => $stock->id],
                ['match_type' => 'keyword', 'matched_term' => $word, 'confidence' => 0.7, 'created_at' => now()],
            );

            // Warm the embedding cache for the linked entity (entity linking aid).
            if ($this->embeddings->isEnabled()) {
                $this->embeddings->embed($word);
            }

            $existing[] = $stock->id;
            $added++;
        }

        return $added;
    }

    private function taskForItem(NewsItem $item): AiTask
    {
        $language = $item->relationLoaded('source')
            ? mb_strtolower((string) $item->source?->language)
            : mb_strtolower((string) optional($item->source()->first())->language);

        return $language === 'tr' ? AiTask::EntityTr : AiTask::EntityEn;
    }
}
