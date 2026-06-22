<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportStooqHistoricalPricesRequest;
use App\Jobs\ImportStooqHistoryJob;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\MarketData\HistoricalPriceImportService;
use App\Services\MarketData\ImportSyncLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminStooqHistoricalPriceController extends Controller
{
    /**
     * Queue a daily-history download from stooq.com for every active NASDAQ stock.
     */
    public function fetchAll(): RedirectResponse
    {
        $query = Stock::query()->active()->where('market', Market::NASDAQ->value);
        $count = (clone $query)->count();

        if ($count === 0) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'No active NASDAQ stocks to update.']);

            return back();
        }

        $query->orderBy('id')->chunkById(200, function (Collection $stocks): void {
            foreach ($stocks as $stock) {
                ImportStooqHistoryJob::dispatch($stock->id);
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Queued Stooq daily history for {$count} NASDAQ stock(s). Processing in the background.",
        ]);

        return back();
    }

    public function store(
        ImportStooqHistoricalPricesRequest $request,
        HistoricalPriceImportService $importer,
    ): RedirectResponse {
        $files = $this->uploadedFiles($request->file('files', []), $request->file('file'));

        if ($files === []) {
            throw ValidationException::withMessages(['files' => 'Uploaded files could not be read.']);
        }

        $fallbackMarket = $request->string('fallback_market')->toString();

        $result = $importer->importBulkFiles(
            $files,
            $fallbackMarket === 'ALL' ? null : Market::from($fallbackMarket),
        );

        ImportSyncLogger::fromSummary(
            ImportSyncLogger::TYPE_BULK_IMPORT,
            StockPrice::PROVIDER_BULK_CSV,
            $result,
            ['files' => count($files)],
        );

        Inertia::flash('stock_import', $result);
        Inertia::flash('toast', [
            'type' => $result['skipped'] > 0 ? 'warning' : 'success',
            'message' => $this->message($result),
        ]);

        return back();
    }

    /**
     * @param  array{imported: int, created: int, updated: int, skipped: int, stocks_created: int}  $result
     */
    private function message(array $result): string
    {
        return "Bulk import finished: {$result['imported']} candles ({$result['created']} new, {$result['updated']} updated), {$result['stocks_created']} stocks created, {$result['skipped']} skipped.";
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function uploadedFiles(mixed $files, mixed $file): array
    {
        $uploadedFiles = [];

        if ($files instanceof UploadedFile) {
            $uploadedFiles[] = $files;
        }

        if (is_array($files)) {
            foreach ($files as $uploadedFile) {
                if ($uploadedFile instanceof UploadedFile) {
                    $uploadedFiles[] = $uploadedFile;
                }
            }
        }

        if ($file instanceof UploadedFile) {
            $uploadedFiles[] = $file;
        }

        return $uploadedFiles;
    }
}
