<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\MarketData\HistoricalPriceImportService;
use App\Services\MarketData\ImportSyncLogger;
use App\Services\MarketData\StooqClient;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Downloads + imports daily OHLC history for a single NASDAQ stock from stooq.com.
 * Dispatched per-stock by the bulk command/scheduler so failures are isolated and
 * the work is throttled by the `stooq` rate limiter. Never called inside a request.
 */
#[UniqueFor(3600)]
class ImportStooqHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    public function __construct(public int $stockId, public ?int $years = null) {}

    public function uniqueId(): string
    {
        return (string) $this->stockId;
    }

    public function handle(StooqClient $client, HistoricalPriceImportService $importer): void
    {
        $stock = Stock::query()->find($this->stockId);

        if ($stock === null || ! $stock->is_active || $stock->market !== Market::NASDAQ) {
            return;
        }

        $years = $this->years ?? (int) config('tradenews.stooq.history_years', 6);
        $csv = $client->fetchDailyCsv($stock, CarbonImmutable::now()->subYears(max(1, $years)));

        if ($csv === null) {
            ImportSyncLogger::empty(
                ImportSyncLogger::TYPE_STOOQ_HISTORY,
                StockPrice::PROVIDER_STOOQ_API,
                'Stooq returned no data (empty history or bot-check block).',
                ['symbol' => $stock->symbol],
            );

            return;
        }

        $summary = $importer->importDailyCsv($stock, $csv);

        ImportSyncLogger::fromSummary(
            ImportSyncLogger::TYPE_STOOQ_HISTORY,
            StockPrice::PROVIDER_STOOQ_API,
            $summary,
            ['symbol' => $stock->symbol],
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('stooq'))->releaseAfter(30)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }
}
