<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\Enums\Market;
use App\Enums\Timeframe;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SplFileObject;
use Throwable;

class HistoricalPriceImportService
{
    private const CHUNK_SIZE = 3000;

    private const MAX_ERRORS = 10;

    /**
     * @return array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}
     */
    public function importManualCsv(Stock $stock, UploadedFile $file, Timeframe $timeframe): array
    {
        $summary = $this->summary('manual-csv');
        $csv = $this->openCsv($file);
        $header = $this->readHeader($csv);

        $this->ensureColumns($header, ['datetime', 'open', 'high', 'low', 'close']);

        $rows = [];

        while (! $csv->eof()) {
            $line = $csv->key();
            $row = $csv->fgetcsv();

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $summary['processed']++;
            $lineNumber = $line + 1;

            try {
                $priceAt = $this->bucketTime(
                    $this->parseManualTimestamp($this->rowValue($row, $header, 'datetime'), $stock->market),
                    $timeframe,
                );

                $rows[] = $this->priceRow(
                    stockId: $stock->id,
                    timeframe: $timeframe,
                    providerKey: StockPrice::PROVIDER_MANUAL_CSV,
                    priceAt: $priceAt,
                    open: $this->requiredNumber($this->rowValue($row, $header, 'open'), 'open'),
                    high: $this->requiredNumber($this->rowValue($row, $header, 'high'), 'high'),
                    low: $this->requiredNumber($this->rowValue($row, $header, 'low'), 'low'),
                    close: $this->requiredNumber($this->rowValue($row, $header, 'close'), 'close'),
                    volume: $this->parseNumber($this->rowValue($row, $header, 'volume')) ?? 0.0,
                );
            } catch (Throwable $e) {
                $this->skip($summary, $lineNumber, $e->getMessage());

                continue;
            }

            $this->flushWhenFull($rows, $summary);
        }

        $this->flushRows($rows, $summary);

        return $summary;
    }

    /**
     * @return array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}
     */
    public function importBulk(UploadedFile $file, ?Market $fallbackMarket): array
    {
        $stocks = $this->stockCache();

        return $this->importBulkWithStockCache($file, $fallbackMarket, $stocks);
    }

    /**
     * @param  iterable<int, UploadedFile>  $files
     * @return array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}
     */
    public function importBulkFiles(iterable $files, ?Market $fallbackMarket): array
    {
        $uploadedFiles = collect($files)
            ->filter(fn (UploadedFile $file): bool => $file->isValid())
            ->values();

        if ($uploadedFiles->count() === 1) {
            return $this->importBulk($uploadedFiles->first(), $fallbackMarket);
        }

        $summary = $this->summary('bulk-upload');
        $stocks = $this->stockCache();

        foreach ($uploadedFiles as $file) {
            try {
                $this->mergeSummary(
                    $summary,
                    $this->importBulkWithStockCache($file, $fallbackMarket, $stocks),
                );
            } catch (ValidationException $e) {
                $summary['skipped']++;
                $this->pushError($summary, $file->getClientOriginalName().': '.$this->validationMessage($e));
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, Stock>  $stocks
     * @return array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}
     */
    private function importBulkWithStockCache(UploadedFile $file, ?Market $fallbackMarket, array &$stocks): array
    {
        $csv = $this->openCsv($file);
        $header = $this->readHeader($csv);

        if ($this->isStooqHeader($header)) {
            return $this->importStooqRows($csv, $header, $fallbackMarket, $stocks);
        }

        if ($this->isGenericBulkHeader($header)) {
            return $this->importGenericBulkRows($csv, $header, $fallbackMarket, $stocks);
        }

        throw ValidationException::withMessages([
            'file' => 'Bulk import needs either Stooq columns or generic columns: symbol, timeframe, datetime, open, high, low, close.',
        ]);
    }

    /**
     * @return array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}
     */
    public function importStooq(UploadedFile $file, ?Market $fallbackMarket): array
    {
        $csv = $this->openCsv($file);
        $header = $this->readHeader($csv);

        $this->ensureColumns($header, ['ticker', 'per', 'date', 'time', 'open', 'high', 'low', 'close', 'vol']);
        $stocks = $this->stockCache();

        return $this->importStooqRows($csv, $header, $fallbackMarket, $stocks);
    }

    /**
     * @param  array<string, int>  $header
     * @param  array<string, Stock>  $stocks
     * @return array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}
     */
    private function importGenericBulkRows(SplFileObject $csv, array $header, ?Market $fallbackMarket, array &$stocks): array
    {
        $summary = $this->summary('bulk-csv');
        $rows = [];

        while (! $csv->eof()) {
            $line = $csv->key();
            $row = $csv->fgetcsv();

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $summary['processed']++;
            $lineNumber = $line + 1;

            try {
                $timeframe = $this->uploadedTimeframe($this->rowValue($row, $header, 'timeframe'));

                if (! $timeframe instanceof Timeframe) {
                    throw new RuntimeException('unsupported timeframe value');
                }

                $market = $this->rowMarket($this->rowValue($row, $header, 'market'), $fallbackMarket);
                [$symbol, $market] = $this->stooqSymbol(
                    $this->rowValue($row, $header, 'symbol') ?? $this->rowValue($row, $header, 'ticker'),
                    $market,
                );
                $stock = $this->stockForSymbol(
                    $stocks,
                    $symbol,
                    $market,
                    $summary,
                    $this->rowValue($row, $header, 'name'),
                );
                $priceAt = $this->bucketTime(
                    $this->parseManualTimestamp($this->rowValue($row, $header, 'datetime'), $market),
                    $timeframe,
                );

                $rows[] = $this->priceRow(
                    stockId: $stock->id,
                    timeframe: $timeframe,
                    providerKey: StockPrice::PROVIDER_BULK_CSV,
                    priceAt: $priceAt,
                    open: $this->requiredNumber($this->rowValue($row, $header, 'open'), 'open'),
                    high: $this->requiredNumber($this->rowValue($row, $header, 'high'), 'high'),
                    low: $this->requiredNumber($this->rowValue($row, $header, 'low'), 'low'),
                    close: $this->requiredNumber($this->rowValue($row, $header, 'close'), 'close'),
                    volume: $this->parseNumber($this->rowValue($row, $header, 'volume')) ?? 0.0,
                );
            } catch (Throwable $e) {
                $this->skip($summary, $lineNumber, $e->getMessage());

                continue;
            }

            $this->flushWhenFull($rows, $summary);
        }

        $this->flushRows($rows, $summary);

        return $summary;
    }

    /**
     * @param  array<string, int>  $header
     * @param  array<string, Stock>  $stocks
     * @return array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}
     */
    private function importStooqRows(SplFileObject $csv, array $header, ?Market $fallbackMarket, array &$stocks): array
    {
        $summary = $this->summary('stooq-upload');

        $rows = [];

        while (! $csv->eof()) {
            $line = $csv->key();
            $row = $csv->fgetcsv();

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $summary['processed']++;
            $lineNumber = $line + 1;

            try {
                $timeframe = $this->stooqTimeframe($this->rowValue($row, $header, 'per'));

                if (! $timeframe instanceof Timeframe) {
                    throw new RuntimeException('unsupported PER value');
                }

                [$symbol, $market] = $this->stooqSymbol($this->rowValue($row, $header, 'ticker'), $fallbackMarket);
                $stock = $this->stockForSymbol($stocks, $symbol, $market, $summary);
                $priceAt = $this->parseStooqTimestamp(
                    $this->rowValue($row, $header, 'date'),
                    $this->rowValue($row, $header, 'time'),
                    $market,
                    $timeframe,
                );

                $rows[] = $this->priceRow(
                    stockId: $stock->id,
                    timeframe: $timeframe,
                    providerKey: StockPrice::PROVIDER_STOOQ_UPLOAD,
                    priceAt: $priceAt,
                    open: $this->requiredNumber($this->rowValue($row, $header, 'open'), 'open'),
                    high: $this->requiredNumber($this->rowValue($row, $header, 'high'), 'high'),
                    low: $this->requiredNumber($this->rowValue($row, $header, 'low'), 'low'),
                    close: $this->requiredNumber($this->rowValue($row, $header, 'close'), 'close'),
                    volume: $this->parseNumber($this->rowValue($row, $header, 'vol')) ?? 0.0,
                );
            } catch (Throwable $e) {
                $this->skip($summary, $lineNumber, $e->getMessage());

                continue;
            }

            $this->flushWhenFull($rows, $summary);
        }

        $this->flushRows($rows, $summary);

        return $summary;
    }

    /**
     * @return array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}
     */
    private function summary(string $source): array
    {
        return [
            'source' => $source,
            'processed' => 0,
            'imported' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'stocks_created' => 0,
            'errors' => [],
        ];
    }

    private function openCsv(UploadedFile $file): SplFileObject
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => 'Uploaded file could not be read.']);
        }

        $csv = new SplFileObject($path);
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl($this->detectDelimiter($path));

        return $csv;
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ',';
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                $counts = [
                    ',' => substr_count($line, ','),
                    ';' => substr_count($line, ';'),
                    "\t" => substr_count($line, "\t"),
                ];

                arsort($counts);

                return (string) array_key_first($counts);
            }
        } finally {
            fclose($handle);
        }

        return ',';
    }

    /**
     * @return array<string, int>
     */
    private function readHeader(SplFileObject $csv): array
    {
        while (! $csv->eof()) {
            $row = $csv->fgetcsv();

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $header = [];

            foreach ($row as $index => $name) {
                $normalized = $this->normalizeHeader((string) $name);

                if ($normalized !== '') {
                    $header[$normalized] = $index;
                }
            }

            if ($header !== []) {
                return $header;
            }
        }

        throw ValidationException::withMessages(['file' => 'The uploaded file does not contain a header row.']);
    }

    /**
     * @param  array<string, int>  $header
     * @param  array<int, string>  $required
     */
    private function ensureColumns(array $header, array $required): void
    {
        $missing = array_values(array_diff($required, array_keys($header)));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'Missing required columns: '.implode(', ', $missing).'.',
            ]);
        }
    }

    /**
     * @param  array<string, int>  $header
     */
    private function isStooqHeader(array $header): bool
    {
        return $this->hasColumns($header, ['ticker', 'per', 'date', 'time', 'open', 'high', 'low', 'close', 'vol']);
    }

    /**
     * @param  array<string, int>  $header
     */
    private function isGenericBulkHeader(array $header): bool
    {
        return $this->hasColumns($header, ['timeframe', 'datetime', 'open', 'high', 'low', 'close'])
            && (array_key_exists('symbol', $header) || array_key_exists('ticker', $header));
    }

    /**
     * @param  array<string, int>  $header
     * @param  array<int, string>  $required
     */
    private function hasColumns(array $header, array $required): bool
    {
        return array_diff($required, array_keys($header)) === [];
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim($value);
        $value = trim($value, '<>');

        return Str::of($value)
            ->lower()
            ->replace([' ', '-'], '_')
            ->replaceMatches('/[^a-z0-9_]/', '')
            ->toString();
    }

    private function isEmptyRow(mixed $row): bool
    {
        if (! is_array($row)) {
            return true;
        }

        foreach ($row as $value) {
            if (is_string($value) && trim($value) !== '') {
                return false;
            }

            if (is_numeric($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $header
     */
    private function rowValue(array $row, array $header, string $column): ?string
    {
        if (! array_key_exists($column, $header)) {
            return null;
        }

        $value = $row[$header[$column]] ?? null;

        if ($value === null) {
            return null;
        }

        return trim((string) $value);
    }

    private function parseManualTimestamp(?string $value, Market $market): CarbonImmutable
    {
        if ($value === null || $value === '') {
            throw new RuntimeException('datetime is required');
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d\TH:i:sP'] as $format) {
            try {
                $timestamp = CarbonImmutable::createFromFormat($format, $value, $market->timezone());
            } catch (Throwable) {
                continue;
            }

            if ($timestamp instanceof CarbonImmutable) {
                return $timestamp;
            }
        }

        try {
            return CarbonImmutable::parse($value, $market->timezone());
        } catch (Throwable) {
            throw new RuntimeException('datetime is invalid');
        }
    }

    private function parseStooqTimestamp(?string $date, ?string $time, Market $market, Timeframe $timeframe): CarbonImmutable
    {
        $date = trim((string) $date);
        $time = trim((string) $time);

        if (! preg_match('/^\d{8}$/', $date)) {
            throw new RuntimeException('DATE must be YYYYMMDD');
        }

        if ($time === '') {
            $time = '000000';
        }

        if (! preg_match('/^\d{6}$/', $time)) {
            throw new RuntimeException('TIME must be HHMMSS');
        }

        $timestamp = $timeframe->isIntraday()
            ? CarbonImmutable::createFromFormat('Ymd His', "{$date} {$time}", $market->timezone())
            : CarbonImmutable::createFromFormat('Ymd', $date, $market->timezone());

        if (! $timestamp instanceof CarbonImmutable) {
            throw new RuntimeException('DATE/TIME is invalid');
        }

        return $this->bucketTime($timestamp, $timeframe);
    }

    private function bucketTime(CarbonImmutable $at, Timeframe $timeframe): CarbonImmutable
    {
        if (! $timeframe->isIntraday()) {
            return $at->startOfDay();
        }

        $timestamp = intdiv($at->getTimestamp(), $timeframe->seconds()) * $timeframe->seconds();

        return CarbonImmutable::createFromTimestamp($timestamp, $at->getTimezone());
    }

    private function stooqTimeframe(?string $per): ?Timeframe
    {
        return match (Str::upper(trim((string) $per))) {
            'D' => Timeframe::OneDay,
            '1' => Timeframe::OneMinute,
            '5' => Timeframe::FiveMinutes,
            '15' => Timeframe::FifteenMinutes,
            '60' => Timeframe::OneHour,
            default => null,
        };
    }

    private function uploadedTimeframe(?string $value): ?Timeframe
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = Str::lower($value);

        return Timeframe::tryFrom($normalized) ?? $this->stooqTimeframe($value);
    }

    private function rowMarket(?string $value, ?Market $fallbackMarket): ?Market
    {
        $value = Str::upper(trim((string) $value));

        if ($value === '') {
            return $fallbackMarket;
        }

        return Market::tryFrom($value) ?? throw new RuntimeException('market is invalid');
    }

    /**
     * @return array{0: string, 1: Market}
     */
    private function stooqSymbol(?string $ticker, ?Market $fallbackMarket): array
    {
        $ticker = Str::upper(trim((string) $ticker));

        if ($ticker === '') {
            throw new RuntimeException('TICKER is required');
        }

        $market = $fallbackMarket;
        $symbol = $ticker;

        if (preg_match('/^(.+)\.([A-Z]{1,5})$/', $ticker, $matches) === 1) {
            $symbol = $matches[1];
            $market = match ($matches[2]) {
                'US' => Market::NASDAQ,
                'IS', 'TR' => Market::BIST,
                default => throw new RuntimeException("unsupported ticker suffix .{$matches[2]}"),
            };
        }

        if (! $market instanceof Market) {
            throw new RuntimeException('market is required when fallback market is All');
        }

        $symbol = trim($symbol);
        $symbol = $market === Market::BIST
            ? preg_replace('/[^A-Z0-9]/', '', $symbol)
            : preg_replace('/[^A-Z0-9.\-]/', '', $symbol);

        if (! is_string($symbol) || $symbol === '') {
            throw new RuntimeException('TICKER symbol is invalid');
        }

        return [$symbol, $market];
    }

    /**
     * @param  array<string, Stock>  $stocks
     * @param  array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}  $summary
     */
    private function stockForSymbol(array &$stocks, string $symbol, Market $market, array &$summary, ?string $name = null): Stock
    {
        $key = "{$market->value}|{$symbol}";

        if (isset($stocks[$key])) {
            return $stocks[$key];
        }

        $stock = Stock::query()->firstOrCreate(
            ['market' => $market->value, 'symbol' => $symbol],
            [
                'name' => $name ?: $symbol,
                'exchange' => $market->label(),
                'currency' => $market->currency(),
                'aliases' => [$symbol],
                'keywords' => [],
                'is_active' => true,
            ],
        );

        if ($stock->wasRecentlyCreated) {
            $summary['stocks_created']++;
        }

        $stocks[$key] = $stock;

        return $stock;
    }

    /**
     * @return array<string, Stock>
     */
    private function stockCache(): array
    {
        $stocks = [];

        Stock::query()
            ->get(['id', 'symbol', 'market'])
            ->each(function (Stock $stock) use (&$stocks): void {
                $stocks[$stock->market->value.'|'.$stock->symbol] = $stock;
            });

        return $stocks;
    }

    private function requiredNumber(?string $value, string $field): float
    {
        $number = $this->parseNumber($value);

        if ($number === null) {
            throw new RuntimeException("{$field} is invalid");
        }

        return $number;
    }

    private function parseNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', trim($value)) ?? '';

        if ($normalized === '' || $normalized === '-' || $normalized === ',' || $normalized === '.') {
            return null;
        }

        $commaCount = substr_count($normalized, ',');
        $dotCount = substr_count($normalized, '.');

        if ($commaCount > 0 && $dotCount > 0) {
            $normalized = strrpos($normalized, ',') > strrpos($normalized, '.')
                ? str_replace('.', '', str_replace(',', '.', $normalized))
                : str_replace(',', '', $normalized);
        } elseif ($commaCount > 1) {
            $normalized = str_replace(',', '', $normalized);
        } elseif ($commaCount === 1) {
            [$whole, $fraction] = explode(',', $normalized, 2);
            $normalized = strlen($fraction) <= 2
                ? $whole.'.'.$fraction
                : $whole.$fraction;
        } elseif ($dotCount > 1) {
            $normalized = str_replace('.', '', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function priceRow(
        int $stockId,
        Timeframe $timeframe,
        string $providerKey,
        CarbonImmutable $priceAt,
        float $open,
        float $high,
        float $low,
        float $close,
        float $volume,
    ): array {
        if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
            throw new RuntimeException('OHLC values must be positive');
        }

        if ($high < max($open, $close) || $low > min($open, $close)) {
            throw new RuntimeException('OHLC values are inconsistent');
        }

        $priceAtValue = $priceAt->toDateTimeString();

        return [
            'stock_id' => $stockId,
            'timeframe' => $timeframe->value,
            'provider_key' => $providerKey,
            'source_kind' => StockPrice::SOURCE_CANDLE,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => max(0.0, $volume),
            'price_at' => $priceAtValue,
            'created_at' => $priceAtValue,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}  $summary
     */
    private function flushWhenFull(array &$rows, array &$summary): void
    {
        if (count($rows) >= self::CHUNK_SIZE) {
            $this->flushRows($rows, $summary);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}  $summary
     */
    private function flushRows(array &$rows, array &$summary): void
    {
        if ($rows === []) {
            return;
        }

        $existing = $this->existingKeys($rows);

        foreach ($rows as $row) {
            if (isset($existing[$this->priceKey($row)])) {
                $summary['updated']++;
            } else {
                $summary['created']++;
            }
        }

        Stock::query()->getConnection()
            ->table((new StockPrice)->getTable())
            ->upsert(
                $rows,
                ['stock_id', 'timeframe', 'price_at'],
                ['provider_key', 'source_kind', 'open', 'high', 'low', 'close', 'volume', 'created_at'],
            );

        $summary['imported'] += count($rows);
        $rows = [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, true>
     */
    private function existingKeys(array $rows): array
    {
        $existing = [];
        $stockIds = array_values(array_unique(array_column($rows, 'stock_id')));
        $timeframes = array_values(array_unique(array_column($rows, 'timeframe')));
        $priceTimes = array_values(array_unique(array_column($rows, 'price_at')));

        StockPrice::query()
            ->whereIn('stock_id', $stockIds)
            ->whereIn('timeframe', $timeframes)
            ->whereIn('price_at', $priceTimes)
            ->get(['stock_id', 'timeframe', 'price_at'])
            ->each(function (StockPrice $price) use (&$existing): void {
                $existing[$this->priceKey([
                    'stock_id' => $price->stock_id,
                    'timeframe' => $price->timeframe->value,
                    'price_at' => $price->price_at->toDateTimeString(),
                ])] = true;
            });

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function priceKey(array $row): string
    {
        return $row['stock_id'].'|'.$row['timeframe'].'|'.$row['price_at'];
    }

    /**
     * @param  array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}  $summary
     */
    private function skip(array &$summary, int $line, string $message): void
    {
        $summary['skipped']++;
        $this->pushError($summary, "Line {$line}: {$message}");
    }

    /**
     * @param  array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}  $summary
     */
    private function pushError(array &$summary, string $message): void
    {
        if (count($summary['errors']) < self::MAX_ERRORS) {
            $summary['errors'][] = $message;
        }
    }

    /**
     * @param  array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}  $summary
     * @param  array{source: string, processed: int, imported: int, created: int, updated: int, skipped: int, stocks_created: int, errors: array<int, string>}  $result
     */
    private function mergeSummary(array &$summary, array $result): void
    {
        foreach (['processed', 'imported', 'created', 'updated', 'skipped', 'stocks_created'] as $key) {
            $summary[$key] += $result[$key];
        }

        foreach ($result['errors'] as $error) {
            $this->pushError($summary, $error);
        }
    }

    private function validationMessage(ValidationException $exception): string
    {
        return (string) (Arr::first(Arr::flatten($exception->errors())) ?: $exception->getMessage());
    }
}
