<?php

declare(strict_types=1);

namespace App\Support\Presenters;

use App\Models\Stock;
use App\Services\MarketData\MarketDataIngestor;

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
        $quote = self::quote($stock);

        return [
            'id' => $stock->id,
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'market' => $stock->market->value,
            'exchange' => $stock->exchange,
            'currency' => $stock->currency,
            'sector' => $stock->sector,
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
        $cached = MarketDataIngestor::cachedQuote($stock->id);

        if ($cached !== null) {
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
}
