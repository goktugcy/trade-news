<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\AiTask;
use App\Models\NewsItem;
use App\Models\Stock;
use App\Services\Ai\AiTaskService;
use App\Services\Ai\EmbeddingService;
use App\Services\Ai\HuggingFaceEndpointClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Optional enhancement layer on top of the deterministic NewsMatcherService.
 * Runs only when the entity/embedding tasks are enabled; never breaks the
 * deterministic matches that already ran.
 */
class NewsEntityEnhancer
{
    private const float MIN_ENTITY_SCORE = 0.82;

    private const float MIN_SIMILARITY_SCORE = 0.55;

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
            ->filter(fn (array $e): bool => (float) ($e['score'] ?? 0.0) >= self::MIN_ENTITY_SCORE)
            ->map(fn (array $e): string => trim((string) ($e['word'] ?? '')))
            ->filter(fn (string $w): bool => mb_strlen($w) >= 3)
            ->unique()
            ->values();

        foreach ($words as $word) {
            $stock = $this->bestStockForEntity($word, $existing);

            if ($stock === null) {
                continue;
            }

            $item->matches()->updateOrCreate(
                ['stock_id' => $stock->id],
                ['match_type' => 'keyword', 'matched_term' => $word, 'confidence' => 0.78, 'created_at' => now()],
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

    /**
     * @param  array<int, int>  $existing
     */
    private function bestStockForEntity(string $word, array $existing): ?Stock
    {
        $candidates = $this->candidateStocksForEntity($word, $existing);

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $scores = $this->embeddings->similarity(
            'query: '.$word,
            $candidates
                ->map(fn (Stock $stock): string => 'passage: '.$this->stockDescription($stock))
                ->values()
                ->all(),
        );

        if ($scores === null) {
            return $candidates->first();
        }

        $bestIndex = 0;
        $bestScore = 0.0;

        foreach ($scores as $index => $score) {
            if ($score > $bestScore) {
                $bestIndex = $index;
                $bestScore = $score;
            }
        }

        if ($bestScore < self::MIN_SIMILARITY_SCORE) {
            return null;
        }

        return $candidates->values()->get($bestIndex);
    }

    /**
     * @param  array<int, int>  $existing
     * @return Collection<int, Stock>
     */
    private function candidateStocksForEntity(string $word, array $existing): Collection
    {
        $needle = $this->normalize($word);

        if ($needle === '') {
            return collect();
        }

        return Stock::query()
            ->active()
            ->whereNotIn('id', $existing)
            ->get()
            ->filter(function (Stock $stock) use ($needle): bool {
                foreach ($stock->matchTerms() as $term) {
                    $term = $this->normalize($term);

                    if ($term === '') {
                        continue;
                    }

                    if ($this->entityMatchesTerm($needle, $term)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    private function entityMatchesTerm(string $needle, string $term): bool
    {
        if ($term === '' || mb_strlen(str_replace(' ', '', $term)) <= 2) {
            return false;
        }

        if ($term === $needle) {
            return true;
        }

        $needle = ' '.$needle.' ';
        $term = ' '.$term.' ';

        return str_contains($needle, $term) || str_contains($term, $needle);
    }

    private function stockDescription(Stock $stock): string
    {
        return trim(implode(' ', array_filter([
            $stock->symbol,
            $stock->name,
            ...($stock->aliases ?? []),
        ])));
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = (string) preg_replace('/[^\pL\pN]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function taskForItem(NewsItem $item): AiTask
    {
        $language = $item->relationLoaded('source')
            ? mb_strtolower((string) $item->source?->language)
            : mb_strtolower((string) optional($item->source()->first())->language);

        return $language === 'tr' ? AiTask::EntityTr : AiTask::EntityEn;
    }
}
