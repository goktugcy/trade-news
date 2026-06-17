<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MatchNewsWithStocksJob;
use App\Models\SystemJob;
use Illuminate\Console\Command;

class MatchNewsCommand extends Command
{
    protected $signature = 'tradenews:match-news';

    protected $description = 'Sweep unmatched news items and match them to stocks';

    public function handle(): int
    {
        SystemJob::track('tradenews:match-news', function (): void {
            MatchNewsWithStocksJob::dispatch();
        });

        $this->info('Dispatched news-matching sweep.');

        return self::SUCCESS;
    }
}
