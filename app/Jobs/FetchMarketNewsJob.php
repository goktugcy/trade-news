<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\News\NewsIngestor;
use App\Services\News\NewsProviderInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Centrally fetches the latest news for a market, dedupes + stores it, then
 * chains sentiment scoring and stock matching for the newly-stored items.
 */
class FetchMarketNewsJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 90;

    public function __construct(public ?Market $market = null) {}

    public function handle(NewsProviderInterface $provider): void
    {
        $created = (new NewsIngestor($provider))->ingest($this->market);

        if ($created->isEmpty()) {
            return;
        }

        // Score sentiment first, then match to stocks (both idempotent).
        CalculateNewsSentimentJob::dispatch($created->pluck('id')->all());
        MatchNewsWithStocksJob::dispatch($created->pluck('id')->all());
    }
}
