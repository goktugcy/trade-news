<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Stock;
use App\Services\Ai\StockAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates AI stock analyses off the HTTP path. No-ops when the stock-analysis
 * task is disabled / unhealthy (the cached result, if any, keeps showing).
 *
 * @param  array<int, int>|null  $stockIds
 */
class GenerateStockAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 300;

    /**
     * @param  array<int, int>|null  $stockIds
     */
    public function __construct(public ?array $stockIds = null) {}

    public function handle(StockAnalyzer $analyzer): void
    {
        if (! $analyzer->isEnabled()) {
            return;
        }

        Stock::query()
            ->active()
            ->when($this->stockIds !== null, fn ($q) => $q->whereIn('id', $this->stockIds))
            ->orderBy('id')
            ->chunkById(25, function ($stocks) use ($analyzer): void {
                foreach ($stocks as $stock) {
                    $analyzer->analyze($stock);
                }
            });
    }
}
