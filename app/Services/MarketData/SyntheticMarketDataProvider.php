<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\CandleData;
use App\DataTransferObjects\QuoteData;
use App\Enums\Timeframe;
use App\Models\Stock;
use Carbon\CarbonImmutable;

/**
 * Generates deterministic, realistic-looking OHLCV data with no external API.
 *
 * The series is seeded from the stock symbol so a given stock always produces a
 * stable base price and walk — this keeps charts coherent across requests while
 * the platform runs out of the box without any API keys.
 */
class SyntheticMarketDataProvider implements MarketDataProviderInterface
{
    public function key(): string
    {
        return 'synthetic';
    }

    public function getQuote(Stock $stock): ?QuoteData
    {
        $candles = $this->getCandles($stock, Timeframe::FiveMinutes, 2);

        if ($candles === []) {
            return null;
        }

        $last = $candles[array_key_last($candles)];
        $prev = $candles[0];

        return new QuoteData(
            symbol: $stock->symbol,
            price: $last->close,
            open: $last->open,
            high: $last->high,
            low: $last->low,
            previousClose: $prev->close,
            volume: $last->volume,
            at: $last->at,
        );
    }

    public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array
    {
        $seed = crc32($stock->symbol.$timeframe->value);
        mt_srand($seed);

        $base = 20 + ($seed % 480);          // stable base price per symbol
        $price = (float) $base;
        $now = CarbonImmutable::now();
        $step = $timeframe->seconds();

        $candles = [];

        for ($i = $limit - 1; $i >= 0; $i--) {
            $at = $now->subSeconds($step * $i);

            // Small mean-reverting random walk.
            $drift = (mt_rand(-100, 100) / 100) * ($base * 0.01);
            $reversion = ($base - $price) * 0.05;
            $open = $price;
            $close = max(0.5, $price + $drift + $reversion);
            $high = max($open, $close) * (1 + mt_rand(0, 60) / 10000);
            $low = min($open, $close) * (1 - mt_rand(0, 60) / 10000);
            $volume = mt_rand(10_000, 5_000_000);

            $candles[] = new CandleData(
                timeframe: $timeframe,
                open: round($open, 4),
                high: round($high, 4),
                low: round($low, 4),
                close: round($close, 4),
                volume: (float) $volume,
                at: $at,
            );

            $price = $close;
        }

        mt_srand();

        return $candles;
    }
}
