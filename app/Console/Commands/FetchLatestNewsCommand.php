<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Jobs\FetchMarketNewsJob;
use App\Models\SystemJob;
use Illuminate\Console\Command;

class FetchLatestNewsCommand extends Command
{
    protected $signature = 'tradenews:fetch-news {--market= : Limit to BIST or NASDAQ}';

    protected $description = 'Dispatch news-fetch jobs (per market) via the configured news provider';

    public function handle(): int
    {
        SystemJob::track('tradenews:fetch-news', function (): void {
            $markets = $this->option('market')
                ? [Market::from($this->option('market'))]
                : Market::cases();

            foreach ($markets as $market) {
                FetchMarketNewsJob::dispatch($market);
            }
        });

        $this->info('Dispatched news-fetch job(s).');

        return self::SUCCESS;
    }
}
