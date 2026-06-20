<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\AiTask;
use App\Enums\Sentiment;
use App\Models\NewsItem;
use App\Services\Ai\AiTaskService;
use App\Services\Ai\HuggingFaceEndpointClient;
use Illuminate\Support\Facades\Log;

/**
 * Applies sentiment to a news item, preferring a Hugging Face financial
 * sentiment classifier (per language) and falling back to the deterministic
 * lexicon analyzer when the task is disabled / unhealthy / fails.
 */
class NewsSentimentService
{
    public function __construct(
        private readonly AiTaskService $tasks,
        private readonly SentimentAnalyzer $fallback,
    ) {}

    public function applyTo(NewsItem $item): void
    {
        $text = trim(implode(' ', array_filter([$item->title, $item->summary])));

        if ($text === '') {
            $this->fallback->applyTo($item);

            return;
        }

        $task = $this->taskForItem($item);
        $model = $this->tasks->modelFor($task);
        $client = $model !== null ? $this->tasks->huggingFaceFor($model) : null;

        if ($model === null || ! $client instanceof HuggingFaceEndpointClient) {
            $this->fallback->applyTo($item);

            return;
        }

        $result = $client->classify($model, $text);

        if (! $result->successful || $result->scores === null || $result->scores === []) {
            $this->tasks->recordFailure($model, $result->error, $result->latencyMs);
            Log::warning('AI sentiment failed; using fallback', ['news_item' => $item->id, 'error' => $result->error]);

            $this->fallback->applyTo($item);

            return;
        }

        $this->tasks->recordSuccess($model, $result->latencyMs);
        $this->store($item, $result->scores, $text);
    }

    private function taskForItem(NewsItem $item): AiTask
    {
        $language = $item->relationLoaded('source')
            ? mb_strtolower((string) $item->source?->language)
            : mb_strtolower((string) optional($item->source()->first())->language);

        return $language === 'tr' ? AiTask::SentimentTr : AiTask::SentimentEn;
    }

    /**
     * @param  array<int, array{label: string, score: float}>  $scores
     */
    private function store(NewsItem $item, array $scores, string $text): void
    {
        usort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = $scores[0];

        $sentiment = $this->mapLabel($top['label']);
        $signed = match ($sentiment) {
            Sentiment::Positive => (float) $top['score'],
            Sentiment::Negative => -(float) $top['score'],
            Sentiment::Neutral => 0.0,
        };

        $item->forceFill([
            'sentiment' => $sentiment,
            'sentiment_score' => round($signed, 4),
            // Reuse the deterministic importance heuristic.
            'importance_score' => $this->fallback->analyze($text)['importance'],
        ])->save();
    }

    private function mapLabel(string $label): Sentiment
    {
        $label = mb_strtolower(trim($label));

        return match (true) {
            str_contains($label, 'pos') || str_contains($label, 'olumlu') || $label === 'label_2' => Sentiment::Positive,
            str_contains($label, 'neg') || str_contains($label, 'olumsuz') || $label === 'label_0' => Sentiment::Negative,
            default => Sentiment::Neutral,
        };
    }
}
