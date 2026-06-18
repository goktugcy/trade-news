<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\News\NewsIngestor;
use App\Services\Providers\ApiProviderRegistry;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches the latest news from ONE source provider (resolved by key), dedupes +
 * merges cross-source duplicates, then chains sentiment + stock matching for the
 * newly-created canonical items. The scheduler fans one job out per active
 * provider so every source contributes (not just the first that returns data).
 */
class FetchMarketNewsJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(
        public string $providerKey,
        public ?Market $market = null,
        public int $limit = 50,
    ) {}

    public function handle(ApiProviderRegistry $registry): void
    {
        $provider = $registry->makeNewsProviderByKey($this->providerKey);

        if ($provider === null) {
            return;
        }

        $created = (new NewsIngestor($provider))->ingest($this->market, $this->limit);

        if ($created->isEmpty()) {
            return;
        }

        $ids = $created->pluck('id')->all();

        // Score sentiment + match stocks + generate AI summaries (all idempotent).
        CalculateNewsSentimentJob::dispatch($ids);
        MatchNewsWithStocksJob::dispatch($ids);
        GenerateNewsSummaryJob::dispatch($ids);
    }
}
