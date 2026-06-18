<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\DataTransferObjects\QuoteData;
use App\Enums\Market;
use App\Enums\NotificationCategory;
use App\Enums\Timeframe;
use App\Models\ApiProvider;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\SyncRun;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\Notification\NotificationCenter;
use App\Services\Providers\ProviderHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class RapidApiBist100SyncService
{
    public const PROVIDER_KEY = 'rapidapi-bist100';

    public function __construct(
        private readonly ProviderHealthService $health,
        private readonly NotificationCenter $notifications,
    ) {}

    public function sync(ApiProvider $provider): SyncRun
    {
        $run = $this->startRun($provider);

        try {
            $client = new RapidApiBist100Client(
                $provider->api_key,
                $provider->base_url ?: RapidApiBist100Client::DEFAULT_BASE_URL,
            );

            $rows = $client->prices();
            $existing = Stock::query()
                ->where('market', Market::BIST->value)
                ->pluck('symbol')
                ->map(fn (string $symbol): string => Str::upper($symbol))
                ->flip();

            $created = 0;
            $updated = 0;
            $processed = 0;

            foreach ($rows as $row) {
                $quote = $this->quoteFromRow($row);

                if (! $quote instanceof QuoteData) {
                    continue;
                }

                $name = $this->stringValue($row, ['name', 'title', 'companyName', 'company_name', 'description', 'hisseAdi'])
                    ?: $quote->symbol;

                $stock = Stock::query()->updateOrCreate(
                    ['market' => Market::BIST->value, 'symbol' => $quote->symbol],
                    [
                        'name' => $name,
                        'exchange' => Market::BIST->label(),
                        'currency' => Market::BIST->currency(),
                        'is_active' => true,
                        'aliases' => array_values(array_unique(array_filter([$quote->symbol, $name]))),
                    ],
                );

                if ($existing->has($quote->symbol)) {
                    $updated++;
                } else {
                    $created++;
                    $existing->put($quote->symbol, true);
                }

                $processed++;

                $this->cacheQuote($stock, $quote);
                $this->upsertQuoteCandles($stock, $quote);
            }

            return $this->finish($provider, $run, $processed, $created, $updated);
        } catch (Throwable $e) {
            return $this->failRun($provider, $run, $e);
        }
    }

    private function startRun(ApiProvider $provider): SyncRun
    {
        return SyncRun::create([
            'type' => 'bist100_quotes',
            'provider_key' => $provider->key,
            'status' => SyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function finish(ApiProvider $provider, SyncRun $run, int $processed, int $created, int $updated): SyncRun
    {
        $previous = $this->previousRun($run);

        $run->update([
            'status' => SyncRun::STATUS_SUCCESS,
            'processed' => $processed,
            'created_count' => $created,
            'updated_count' => $updated,
            'finished_at' => now(),
        ]);

        $this->health->recordSuccess($provider->key, 'sync:'.$run->type);

        if ($previous?->status === SyncRun::STATUS_FAILED) {
            $this->notifications->toAdmins(
                NotificationCategory::Sync,
                'sync_recovered',
                'BIST100 quote sync recovered',
                "Processed {$processed} ({$created} new, {$updated} updated).",
                ['type' => $run->type],
                '/admin/sync-logs',
            );
        }

        return $run;
    }

    private function failRun(ApiProvider $provider, SyncRun $run, Throwable $e): SyncRun
    {
        $run->update([
            'status' => SyncRun::STATUS_FAILED,
            'finished_at' => now(),
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);

        $this->health->recordFailure($provider->key, $e->getMessage());

        $this->notifications->toAdmins(
            NotificationCategory::Sync,
            'sync_failed',
            'BIST100 quote sync failed',
            mb_substr($e->getMessage(), 0, 300),
            ['type' => $run->type],
            '/admin/sync-logs',
        );

        return $run;
    }

    private function previousRun(SyncRun $run): ?SyncRun
    {
        return SyncRun::query()
            ->where('type', $run->type)
            ->where('id', '<', $run->id)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function quoteFromRow(array $row): ?QuoteData
    {
        $symbol = $this->symbolValue($row);
        $price = $this->numberValue($row, ['price', 'last', 'lastPrice', 'last_price', 'close', 'current', 'currentPrice', 'fiyat', 'son']);

        if ($symbol === null || $price === null || $price <= 0.0) {
            return null;
        }

        $open = $this->numberValue($row, ['open', 'opening', 'openPrice', 'open_price', 'acilis']) ?? $price;
        $high = $this->numberValue($row, ['high', 'dayHigh', 'day_high', 'highPrice', 'yuksek']) ?? max($open, $price);
        $low = $this->numberValue($row, ['low', 'dayLow', 'day_low', 'lowPrice', 'dusuk']) ?? min($open, $price);
        $previousClose = $this->previousCloseValue($row, $price, $open);
        $volume = $this->numberValue($row, ['volume_lot', 'volumeLot', 'lot_volume', 'volume', 'vol', 'hacim', 'volume_turkish_lira']) ?? 0.0;

        return new QuoteData(
            symbol: $symbol,
            price: $price,
            open: $open,
            high: max($high, $price),
            low: min($low, $price),
            previousClose: $previousClose,
            volume: $volume,
            at: $this->timestampValue($row),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function symbolValue(array $row): ?string
    {
        $symbol = $this->stringValue($row, ['symbol', 'code', 'ticker', 'hisse', 'hisseKodu', 'stockCode']);

        if ($symbol === null) {
            return null;
        }

        $symbol = Str::upper(trim($symbol));
        $symbol = preg_replace('/^BIST[:.\-]?/i', '', $symbol) ?: $symbol;
        $symbol = preg_replace('/\.IS$/i', '', $symbol) ?: $symbol;
        $symbol = preg_replace('/[^A-Z0-9]/', '', $symbol) ?: '';

        return $symbol === '' ? null : $symbol;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function stringValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->valueForKey($row, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function previousCloseValue(array $row, float $price, float $fallback): float
    {
        $previousClose = $this->numberValue($row, ['previousClose', 'previous_close', 'prevClose', 'prev_close', 'yesterdayClose', 'oncekiKapanis']);

        if ($previousClose !== null) {
            return $previousClose;
        }

        $dailyChange = $this->numberValue($row, ['daily_change_price', 'dailyChangePrice', 'change_price', 'change']);

        if ($dailyChange !== null) {
            return max(0.0, $price - $dailyChange);
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function numberValue(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->valueForKey($row, $key);
            $number = $this->parseNumber($value);

            if ($number !== null) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function timestampValue(array $row): CarbonImmutable
    {
        foreach (['date', 'datetime', 'timestamp', 'time', 'lastUpdate', 'last_update', 'updated_at'] as $key) {
            $value = $this->valueForKey($row, $key);

            if (is_numeric($value)) {
                return CarbonImmutable::createFromTimestamp((int) $value);
            }

            if (is_string($value) && trim($value) !== '') {
                try {
                    return $this->parseTimestamp($value);
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return CarbonImmutable::now();
    }

    private function parseTimestamp(string $value): CarbonImmutable
    {
        $value = trim($value);

        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP'] as $format) {
            $timestamp = CarbonImmutable::createFromFormat($format, $value, Market::BIST->timezone());

            if ($timestamp instanceof CarbonImmutable) {
                return $timestamp;
            }
        }

        return CarbonImmutable::parse($value, Market::BIST->timezone());
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function valueForKey(array $row, string $key): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        $target = Str::lower($key);

        foreach ($row as $rowKey => $value) {
            if (Str::lower((string) $rowKey) === $target) {
                return $value;
            }
        }

        return null;
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

    private function cacheQuote(Stock $stock, QuoteData $quote): void
    {
        Cache::put(
            MarketDataIngestor::quoteCacheKey($stock->id),
            $quote->toArray() + [
                'name' => $stock->name,
                'currency' => $stock->currency,
                'provider_key' => self::PROVIDER_KEY,
                'source_kind' => StockPrice::SOURCE_QUOTE,
            ],
            config('tradenews.cache.quote_ttl', 60),
        );
    }

    private function upsertQuoteCandles(Stock $stock, QuoteData $quote): void
    {
        $rows = array_map(
            fn (Timeframe $timeframe): array => $this->quoteCandleRow($stock, $quote, $timeframe),
            MarketDataIngestor::SYNC_TIMEFRAMES,
        );

        Stock::query()->getConnection()
            ->table((new StockPrice)->getTable())
            ->upsert(
                $rows,
                ['stock_id', 'timeframe', 'price_at'],
                ['provider_key', 'source_kind', 'open', 'high', 'low', 'close', 'volume'],
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteCandleRow(Stock $stock, QuoteData $quote, Timeframe $timeframe): array
    {
        $price = $quote->price;

        return [
            'stock_id' => $stock->id,
            'timeframe' => $timeframe->value,
            'provider_key' => self::PROVIDER_KEY,
            'source_kind' => StockPrice::SOURCE_QUOTE,
            'open' => $timeframe->isIntraday() ? $price : $quote->open,
            'high' => $timeframe->isIntraday() ? $price : $quote->high,
            'low' => $timeframe->isIntraday() ? $price : $quote->low,
            'close' => $price,
            'volume' => $quote->volume,
            'price_at' => $this->bucketTime($quote->at, $timeframe)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ];
    }

    private function bucketTime(CarbonImmutable $at, Timeframe $timeframe): CarbonImmutable
    {
        $timestamp = intdiv($at->getTimestamp(), $timeframe->seconds()) * $timeframe->seconds();

        return CarbonImmutable::createFromTimestamp($timestamp);
    }
}
