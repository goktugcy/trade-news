<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Stock;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\MarketData\MarketDataProviderInterface;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Fetches + stores prices for a single stock via the configured provider.
 *
 * Dispatched per-stock by the price scheduler so failures are isolated and the
 * work fans out across queue workers. Never called inside an HTTP request.
 */
#[UniqueFor(3600)]
class FetchStockPricesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    public int $timeout = 60;

    public function __construct(public int $stockId, public bool $force = false) {}

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

        // Re-check at execution time: another provider/job may have priced this
        // stock since the job was queued, so skip it unless forced.
        if (! $this->force && $stock->pricedWithin((int) config('tradenews.market_data.fresh_within_minutes', 10))) {
            return;
        }

        (new MarketDataIngestor($provider))->sync($stock);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('market-data-provider'))->releaseAfter(60)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }
}
