<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NewsItem;
use App\Services\News\NewsSentimentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores sentiment + importance for news items.
 *
 * With explicit ids it scores those; otherwise it sweeps items missing a score.
 */
class CalculateNewsSentimentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<int, int>|null  $newsItemIds
     */
    public function __construct(public ?array $newsItemIds = null) {}

    public function handle(NewsSentimentService $sentiment): void
    {
        NewsItem::query()
            ->with('source:id,language')
            ->when(
                $this->newsItemIds !== null,
                fn ($q) => $q->whereIn('id', $this->newsItemIds),
                fn ($q) => $q->whereNull('sentiment'),
            )
            ->chunkById(200, function ($items) use ($sentiment): void {
                foreach ($items as $item) {
                    $sentiment->applyTo($item);
                }
            });
    }
}
