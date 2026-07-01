<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MarketData\FmpQuoteSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Batch-syncs latest quotes for index-member stocks via FMP. One job per run
 * (unique) so overlapping schedules don't double-fetch. Never called inside an
 * HTTP request — dispatched by the tradenews:sync-quotes command.
 */
#[UniqueFor(120)]
class SyncQuotesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function uniqueId(): string
    {
        return 'fmp-quotes';
    }

    public function handle(FmpQuoteSyncService $service): void
    {
        $service->sync();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('market-data-provider'))->releaseAfter(60)];
    }
}
