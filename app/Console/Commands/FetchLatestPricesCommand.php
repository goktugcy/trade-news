<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchStockPricesJob;
use App\Models\Stock;
use App\Models\SystemJob;
use Illuminate\Console\Command;

class FetchLatestPricesCommand extends Command
{
    protected $signature = 'tradenews:fetch-prices {--market= : Limit to BIST or NASDAQ}';

    protected $description = 'Dispatch price-fetch jobs for every active stock';

    public function handle(): int
    {
        $count = SystemJob::track('tradenews:fetch-prices', function (SystemJob $job): int {
            $dispatched = 0;

            Stock::query()
                ->active()
                ->when($this->option('market'), fn ($q) => $q->market($this->option('market')))
                ->select('id')
                ->chunkById(500, function ($stocks) use (&$dispatched): void {
                    foreach ($stocks as $stock) {
                        FetchStockPricesJob::dispatch($stock->id);
                        $dispatched++;
                    }
                });

            $job->update(['meta' => ['dispatched' => $dispatched]]);

            return $dispatched;
        });

        $this->info("Dispatched {$count} price-fetch job(s).");

        return self::SUCCESS;
    }
}
