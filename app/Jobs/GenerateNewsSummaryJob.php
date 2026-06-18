<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NewsItem;
use App\Services\News\AiSummarizerInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Generates AI summaries for news items. Given explicit ids it summarizes those;
 * otherwise it sweeps items still missing a summary. No-ops cheaply when AI is
 * disabled (NullSummarizer), and rate-limited to respect the provider's quota.
 */
class GenerateNewsSummaryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    /**
     * @param  array<int, int>|null  $newsItemIds
     */
    public function __construct(public ?array $newsItemIds = null) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('ai-summary'))->releaseAfter(30)];
    }

    public function handle(AiSummarizerInterface $summarizer): void
    {
        if (! $summarizer->isEnabled()) {
            return;
        }

        NewsItem::query()
            ->whereNull('ai_summary')
            ->when(
                $this->newsItemIds !== null,
                fn ($q) => $q->whereIn('id', $this->newsItemIds),
            )
            ->orderByDesc('importance_score')
            ->limit(50)
            ->get()
            ->each(function (NewsItem $item) use ($summarizer): void {
                $summary = $summarizer->summarize($item);

                if ($summary !== null) {
                    $item->forceFill([
                        'ai_summary' => $summary,
                        'ai_summary_generated_at' => now(),
                    ])->save();
                }
            });
    }
}
