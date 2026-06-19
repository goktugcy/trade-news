<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Timeframe;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportStockHistoricalPricesRequest;
use App\Models\Stock;
use App\Services\MarketData\HistoricalPriceImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminStockHistoricalPriceController extends Controller
{
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
