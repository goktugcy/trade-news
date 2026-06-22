<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Jobs\ImportStooqHistoryJob;
use App\Models\Stock;
use App\Services\MarketData\HistoricalPriceImportService;
use App\Services\MarketData\StooqClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ImportStooqHistoryCommand extends Command
{
    protected $signature = 'tradenews:import-stooq-history
        {--symbols= : Comma-separated symbols to import (default: all active NASDAQ)}
        {--years= : Years of daily history to fetch (default: config tradenews.stooq.history_years)}
        {--sync : Import inline instead of dispatching queued jobs}';

    protected $description = 'Download + import daily OHLC history for NASDAQ stocks from stooq.com';

    public function handle(StooqClient $client, HistoricalPriceImportService $importer): int
    {
        $years = $this->option('years') !== null
            ? max(1, (int) $this->option('years'))
            : (int) config('tradenews.stooq.history_years', 6);

        $symbols = collect(explode(',', (string) $this->option('symbols')))
            ->map(fn (string $symbol): string => mb_strtoupper(trim($symbol)))
            ->filter()
            ->values();

        $query = Stock::query()
            ->active()
            ->where('market', Market::NASDAQ->value)
            ->when($symbols->isNotEmpty(), fn (Builder $q) => $q->whereIn('symbol', $symbols))
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No active NASDAQ stocks matched.');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $imported = 0;
            $query->chunkById(100, function ($stocks) use ($client, $importer, $years, &$imported): void {
                foreach ($stocks as $stock) {
                    $csv = $client->fetchDailyCsv($stock, now()->subYears($years));

                    if ($csv !== null) {
                        $importer->importDailyCsv($stock, $csv);
                        $imported++;
                    }
                }
            });

            $this->info("Imported Stooq history for {$imported}/{$total} stock(s).");

            return self::SUCCESS;
        }

        $query->chunkById(100, function ($stocks) use ($years): void {
            foreach ($stocks as $stock) {
                ImportStooqHistoryJob::dispatch($stock->id, $years);
            }
        });

        $this->info("Dispatched Stooq history jobs for {$total} NASDAQ stock(s).");

        return self::SUCCESS;
    }
}
