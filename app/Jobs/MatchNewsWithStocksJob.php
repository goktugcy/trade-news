<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NewsItem;
use App\Services\News\NewsEntityEnhancer;
use App\Services\News\NewsMatcherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Matches news items to the stocks they mention.
 *
 * If given explicit ids, matches just those; otherwise sweeps any unmatched
 * items (the scheduler runs the sweep variant on a cadence as a safety net).
 */
class MatchNewsWithStocksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<int, int>|null  $newsItemIds
     */
    public function __construct(
        public ?array $newsItemIds = null,
        public bool $repairMarkets = false,
    ) {}

    public function handle(NewsMatcherService $matcher): void
    {
        $enhancer = app(NewsEntityEnhancer::class);

        $query = NewsItem::query()
            ->with('source:id,language')
            ->when(
                $this->newsItemIds !== null,
                fn ($q) => $q->whereIn('id', $this->newsItemIds),
                fn ($q) => $this->repairMarkets ? $q : $q->where('is_matched', false),
            );

        $query->chunkById(200, function ($items) use ($matcher, $enhancer): void {
            foreach ($items as $item) {
                // Deterministic matching always runs first.
                $matcher->match($item);

                // Optional AI entity linking, only when the task is enabled.
                if ($enhancer->isEnabled($item)) {
                    $enhancer->enhance($item);
                }
            }
        });
    }
}
