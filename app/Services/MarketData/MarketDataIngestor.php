<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\QuoteData;
use App\Enums\Timeframe;
use App\Models\Stock;
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
            $this->cacheQuote($stock, $quote);
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

        $rows = array_map(fn ($candle) => $candle->toRow($stock->id), $candles);

        Stock::query()->getConnection()
            ->table('stock_prices')
            ->upsert(
                $rows,
                ['stock_id', 'timeframe', 'price_at'],
                ['open', 'high', 'low', 'close', 'volume'],
            );

        return count($rows);
    }

    public function cacheQuote(Stock $stock, QuoteData $quote): void
    {
        Cache::put(
            self::quoteCacheKey($stock->id),
            $quote->toArray() + ['name' => $stock->name, 'currency' => $stock->currency],
            config('tradenews.cache.quote_ttl', 60),
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
}
