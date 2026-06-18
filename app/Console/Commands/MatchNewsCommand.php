<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MatchNewsWithStocksJob;
use App\Models\SystemJob;
use App\Services\News\NewsMatcherService;
use Illuminate\Console\Command;

class MatchNewsCommand extends Command
{
    protected $signature = 'tradenews:match-news
        {--repair-markets : Re-run already matched news items to repair market labels}
        {--sync : Run immediately instead of dispatching the matcher job to the queue}';

    protected $description = 'Sweep unmatched news items and match them to stocks';

    public function handle(NewsMatcherService $matcher): int
    {
        SystemJob::track('tradenews:match-news', function (SystemJob $job) use ($matcher): void {
            $repairMarkets = (bool) $this->option('repair-markets');
            $sync = (bool) $this->option('sync');

            if ($sync) {
                (new MatchNewsWithStocksJob(repairMarkets: $repairMarkets))->handle($matcher);
            } else {
                MatchNewsWithStocksJob::dispatch(repairMarkets: $repairMarkets);
            }

            $job->update(['meta' => [
                'repair_markets' => $repairMarkets,
                'mode' => $sync ? 'sync' : 'queued',
            ]]);
        });

        $this->info('Dispatched news-matching sweep.');

        return self::SUCCESS;
    }
}
