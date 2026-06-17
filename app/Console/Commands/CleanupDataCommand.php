<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CleanupOldDataJob;
use App\Models\SystemJob;
use Illuminate\Console\Command;

class CleanupDataCommand extends Command
{
    protected $signature = 'tradenews:cleanup';

    protected $description = 'Prune old prices/news/notifications and clean duplicate news';

    public function handle(): int
    {
        SystemJob::track('tradenews:cleanup', function (): void {
            CleanupOldDataJob::dispatch();
        });

        $this->info('Dispatched cleanup job.');

        return self::SUCCESS;
    }
}
