<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Market;
use App\Enums\Timeframe;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportStockHistoricalPricesRequest;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\MarketData\HistoricalPriceImportService;
use App\Services\MarketData\ImportSyncLogger;
use App\Services\MarketData\StooqClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminStockHistoricalPriceController extends Controller
{
    /**
     * Download + import a single NASDAQ stock's daily history from stooq.com,
     * synchronously (one symbol is fast) so the admin sees the result at once.
     */
    public function fetchStooq(
        Stock $stock,
        StooqClient $client,
        HistoricalPriceImportService $importer,
    ): RedirectResponse {
        if ($stock->market !== Market::NASDAQ) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Stooq fetch currently supports NASDAQ symbols only.']);

            return back();
        }

        $years = (int) config('tradenews.stooq.history_years', 6);
        $csv = $client->fetchDailyCsv($stock, CarbonImmutable::now()->subYears(max(1, $years)));

        if ($csv === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Stooq returned no data for {$stock->symbol}."]);

            return back();
        }

        $result = $importer->importDailyCsv($stock, $csv);

        ImportSyncLogger::fromSummary(
            ImportSyncLogger::TYPE_STOOQ_HISTORY,
            StockPrice::PROVIDER_STOOQ_API,
            $result,
            ['symbol' => $stock->symbol, 'trigger' => 'manual'],
        );

        Inertia::flash('stock_import', $result);
        Inertia::flash('toast', [
            'type' => $result['skipped'] > 0 ? 'warning' : 'success',
            'message' => $this->message($result),
        ]);

        return back();
    }

    public function store(
        ImportStockHistoricalPricesRequest $request,
        Stock $stock,
        HistoricalPriceImportService $importer,
    ): RedirectResponse {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Uploaded file could not be read.']);
        }

        $result = $importer->importManualCsv(
            $stock,
            $file,
            Timeframe::from($request->string('timeframe')->toString()),
        );

        ImportSyncLogger::fromSummary(
            ImportSyncLogger::TYPE_MANUAL_IMPORT,
            StockPrice::PROVIDER_MANUAL_CSV,
            $result,
            ['symbol' => $stock->symbol, 'trigger' => 'upload'],
        );

        Inertia::flash('stock_import', $result);
        Inertia::flash('toast', [
            'type' => $result['skipped'] > 0 ? 'warning' : 'success',
            'message' => $this->message($result),
        ]);

        return back();
    }

    /**
     * @param  array{imported: int, created: int, updated: int, skipped: int}  $result
     */
    private function message(array $result): string
    {
        return "Historical import finished: {$result['imported']} candles ({$result['created']} new, {$result['updated']} updated), {$result['skipped']} skipped.";
    }
}
