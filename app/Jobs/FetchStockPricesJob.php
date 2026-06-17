<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Stock;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\MarketData\MarketDataProviderInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches + stores prices for a single stock via the configured provider.
 *
 * Dispatched per-stock by the price scheduler so failures are isolated and the
 * work fans out across queue workers. Never called inside an HTTP request.
 */
class FetchStockPricesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(public int $stockId) {}

    /**
     * Avoid piling up duplicate work for the same stock in the queue.
     */
    public function uniqueId(): string
    {
        return (string) $this->stockId;
    }

    public function handle(MarketDataProviderInterface $provider): void
    {
        $stock = Stock::query()->find($this->stockId);

        if ($stock === null || ! $stock->is_active) {
            return;
        }

        (new MarketDataIngestor($provider))->sync($stock);
    }
}
