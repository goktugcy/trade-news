<?php

declare(strict_types=1);

namespace App\Support\Presenters;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\Providers\ApiProviderRegistry;

/**
 * Shapes stocks (with their cached quote, falling back to the latest stored
 * candle) into the array contract consumed by the Vue UI.
 */
class StockPresenter
{
    /**
     * @param  array<string, bool|int|null>  $flags  e.g. ['in_watchlist' => true, 'watchlist_id' => 3]
     * @return array<string, mixed>
     */
    public static function row(Stock $stock, array $flags = []): array
    {
        return self::rowWithQuote($stock, self::quote($stock), $flags);
    }

    /**
     * Assemble a row from a stock + an already-resolved quote (no per-stock
     * lookups). Lets batch callers (e.g. MarketSummaryService) avoid N+1.
     *
     * @param  array<string, mixed>  $quote
     * @param  array<string, bool|int|null>  $flags
     * @return array<string, mixed>
     */
    public static function rowWithQuote(Stock $stock, array $quote, array $flags = []): array
    {
        return [
            'id' => $stock->id,
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'market' => $stock->market->value,
            'exchange' => $stock->exchange,
            'tradingview_symbol' => $stock->tradingViewSymbol(),
            'currency' => $stock->currency,
            'sector' => $stock->sector,
            'industry' => $stock->industry,
            'market_cap' => $stock->market_cap,
            'website' => $stock->website,
            'is_active' => $stock->is_active,
            'price' => $quote['price'] ?? null,
            'change' => $quote['change'] ?? null,
            'change_percent' => $quote['change_percent'] ?? null,
            'quote_at' => $quote['at'] ?? null,
            ...$flags,
        ];
    }

    /**
     * Resolve a quote from cache; fall back to the latest stored daily/intraday
     * candles. Never calls a provider (read-only path).
     *
     * @return array<string, mixed>
     */
    public static function quote(Stock $stock): array
    {
        $hideSynthetic = self::hideSyntheticMarketData();
        $cached = MarketDataIngestor::cachedQuote($stock->id);

        if ($cached !== null && ! self::shouldHideCachedQuote($cached, $hideSynthetic)) {
            return $cached;
        }

        $latest = $stock->relationLoaded('latestPrice')
            ? $stock->latestPrice
            : $stock->latestPrice()->first();

        if ($latest === null) {
            return [];
        }

        // Approximate change from the previous candle's close (the column value,
        // or the latest open when this is the only candle).
        $previousClose = $stock->prices()
            ->withoutSyntheticWhenApiActive($hideSynthetic)
            ->where('timeframe', $latest->timeframe->value)
            ->where('price_at', '<', $latest->price_at)
            ->orderByDesc('price_at')
            ->value('close') ?? $latest->open;
        $change = round($latest->close - $previousClose, 4);
        $changePercent = $previousClose != 0.0
            ? round(($change / $previousClose) * 100, 2)
            : 0.0;

        return [
            'price' => $latest->close,
            'change' => $change,
            'change_percent' => $changePercent,
            'at' => $latest->price_at->toIso8601String(),
        ];
    }

    public static function hideSyntheticMarketData(): bool
    {
        return app(ApiProviderRegistry::class)->shouldHideSyntheticMarketData();
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    public static function shouldHideCachedQuote(array $quote, bool $apiActive): bool
    {
        if (! $apiActive) {
            return false;
        }

        $providerKey = is_string($quote['provider_key'] ?? null) ? $quote['provider_key'] : null;
        $sourceKind = is_string($quote['source_kind'] ?? null) ? $quote['source_kind'] : null;

        return $providerKey === null
            || ApiProviderRegistry::isSyntheticKey($providerKey)
            || $sourceKind === StockPrice::SOURCE_SYNTHETIC;
    }
}
