<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\QuoteData;
use App\Enums\Timeframe;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Centrally fetches market data via the configured provider, upserts candles
 * into Postgres, and caches the latest quote. User-facing requests read the
 * cache / DB only — they never call a provider directly.
 */
class MarketDataIngestor
{
    /** Timeframes pulled on every price refresh. */
    public const SYNC_TIMEFRAMES = [Timeframe::FiveMinutes, Timeframe::OneDay];

    public function __construct(
        private readonly MarketDataProviderInterface $provider,
    ) {}

    public static function quoteCacheKey(int $stockId): string
    {
        return "tn:quote:{$stockId}";
    }

    /**
     * Sync one stock: upsert candles for each timeframe and cache the quote.
     */
    public function sync(Stock $stock): void
    {
        foreach (self::SYNC_TIMEFRAMES as $timeframe) {
            $this->ingestCandles($stock, $timeframe);
        }

        $quote = $this->provider->getQuote($stock);

        if ($quote instanceof QuoteData) {
            $providerKey = $this->quoteProviderKey();

            $this->cacheQuote($stock, $quote, $providerKey);
            $this->upsertQuoteCandles($stock, $quote, $providerKey);
        }
    }

    /**
     * Upsert provider candles into stock_prices (idempotent on the unique key).
     *
     * @return int number of candles written
     */
    public function ingestCandles(Stock $stock, Timeframe $timeframe, int $limit = 150): int
    {
        $candles = $this->provider->getCandles($stock, $timeframe, $limit);

        if ($candles === []) {
            return 0;
        }

        $providerKey = $this->candleProviderKey($timeframe);

        $rows = array_map(fn ($candle) => $candle->toRow($stock->id) + [
            'provider_key' => $providerKey,
            'source_kind' => StockPrice::SOURCE_CANDLE,
        ], $candles);

        Stock::query()->getConnection()
            ->table((new StockPrice)->getTable())
            ->upsert(
                $rows,
                ['stock_id', 'timeframe', 'price_at'],
                ['provider_key', 'source_kind', 'open', 'high', 'low', 'close', 'volume'],
            );

        return count($rows);
    }

    public function cacheQuote(Stock $stock, QuoteData $quote, ?string $providerKey = null): void
    {
        Cache::put(
            self::quoteCacheKey($stock->id),
            $quote->toArray() + [
                'name' => $stock->name,
                'currency' => $stock->currency,
                'provider_key' => $providerKey ?? $this->provider->key(),
                'source_kind' => StockPrice::SOURCE_QUOTE,
            ],
            config('tradenews.cache.quote_ttl', 60),
        );
    }

    public function upsertQuoteCandles(Stock $stock, QuoteData $quote, ?string $providerKey = null): void
    {
        $rows = array_map(
            fn (Timeframe $timeframe): array => $this->quoteCandleRow($stock, $quote, $timeframe, $providerKey),
            self::SYNC_TIMEFRAMES,
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
     * Read a cached quote for a stock (used by the UI / right-rail panels).
     *
     * @return array<string, mixed>|null
     */
    public static function cachedQuote(int $stockId): ?array
    {
        return Cache::get(self::quoteCacheKey($stockId));
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteCandleRow(Stock $stock, QuoteData $quote, Timeframe $timeframe, ?string $providerKey): array
    {
        $priceAt = $this->bucketTime($quote->at, $timeframe);
        $price = $quote->price;

        return [
            'stock_id' => $stock->id,
            'timeframe' => $timeframe->value,
            'provider_key' => $providerKey ?? $this->provider->key(),
            'source_kind' => StockPrice::SOURCE_QUOTE,
            'open' => $timeframe->isIntraday() ? $price : $quote->open,
            'high' => $timeframe->isIntraday() ? $price : $quote->high,
            'low' => $timeframe->isIntraday() ? $price : $quote->low,
            'close' => $price,
            'volume' => $quote->volume,
            'price_at' => $priceAt->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ];
    }

    private function bucketTime(CarbonImmutable $at, Timeframe $timeframe): CarbonImmutable
    {
        $timestamp = intdiv($at->getTimestamp(), $timeframe->seconds()) * $timeframe->seconds();

        return CarbonImmutable::createFromTimestamp($timestamp);
    }

    private function quoteProviderKey(): string
    {
        if ($this->provider instanceof FallbackMarketDataProvider) {
            return $this->provider->lastQuoteProviderKey() ?? $this->provider->key();
        }

        return $this->provider->key();
    }

    private function candleProviderKey(Timeframe $timeframe): string
    {
        if ($this->provider instanceof FallbackMarketDataProvider) {
            return $this->provider->lastCandleProviderKey($timeframe) ?? $this->provider->key();
        }

        return $this->provider->key();
    }
}
