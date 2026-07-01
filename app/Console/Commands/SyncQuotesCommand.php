<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncQuotesJob;
use App\Services\MarketData\FmpQuoteSyncService;
use App\Models\SystemJob;
use Illuminate\Console\Command;

class SyncQuotesCommand extends Command
{
    protected $signature = 'tradenews:sync-quotes
        {--now : Run the batch quote sync inline instead of queueing the job}';

    protected $description = 'Batch-sync latest quotes for NASDAQ-100 + S&P 500 members via FMP';

    public function handle(): int
    {
        if ($this->option('now')) {
            $synced = SystemJob::track('tradenews:sync-quotes', fn (): int => app(FmpQuoteSyncService::class)->sync());

            $this->info("Synced {$synced} quotes.");

            return self::SUCCESS;
        }

        SyncQuotesJob::dispatch();
        $this->info('Quote sync dispatched.');

        return self::SUCCESS;
    }
}
