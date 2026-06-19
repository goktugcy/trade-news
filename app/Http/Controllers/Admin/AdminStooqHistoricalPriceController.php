<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportStooqHistoricalPricesRequest;
use App\Services\MarketData\HistoricalPriceImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminStooqHistoricalPriceController extends Controller
{
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
