<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\CandleData;
use App\DataTransferObjects\QuoteData;
use App\Enums\Timeframe;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Real market-data integration against the Twelve Data REST API.
 *
 * @see https://twelvedata.com/docs
 */
class TwelveDataProvider implements MarketDataProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.twelvedata.com',
    ) {}

    public function key(): string
    {
        return 'twelvedata';
    }

    public function getQuote(Stock $stock): ?QuoteData
    {
        $data = Http::baseUrl($this->baseUrl)
            ->timeout(8)
            ->retry(2, 200)
            ->get('/quote', ['symbol' => $stock->symbol, 'apikey' => $this->apiKey])
            ->json();

        if (! is_array($data) || ! isset($data['close'])) {
            return null;
        }

        return new QuoteData(
            symbol: $stock->symbol,
            price: (float) $data['close'],
            open: (float) ($data['open'] ?? $data['close']),
            high: (float) ($data['high'] ?? $data['close']),
            low: (float) ($data['low'] ?? $data['close']),
            previousClose: (float) ($data['previous_close'] ?? $data['close']),
            volume: (float) ($data['volume'] ?? 0),
            at: CarbonImmutable::now(),
        );
    }

    public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array
    {
        $data = Http::baseUrl($this->baseUrl)
            ->timeout(10)
            ->retry(2, 200)
            ->get('/time_series', [
                'symbol' => $stock->symbol,
                'interval' => $this->interval($timeframe),
                'outputsize' => $limit,
                'apikey' => $this->apiKey,
            ])
            ->json();

        $values = $data['values'] ?? null;

        if (! is_array($values)) {
            return [];
        }

        // Twelve Data returns newest → oldest; we want oldest → newest.
        $candles = [];

        foreach (array_reverse($values) as $row) {
            $candles[] = new CandleData(
                timeframe: $timeframe,
                open: (float) $row['open'],
                high: (float) $row['high'],
                low: (float) $row['low'],
                close: (float) $row['close'],
                volume: (float) ($row['volume'] ?? 0),
                at: CarbonImmutable::parse($row['datetime']),
            );
        }

        return $candles;
    }

    private function interval(Timeframe $timeframe): string
    {
        return match ($timeframe) {
            Timeframe::OneMinute => '1min',
            Timeframe::FiveMinutes => '5min',
            Timeframe::FifteenMinutes => '15min',
            Timeframe::OneHour => '1h',
            Timeframe::OneDay => '1day',
        };
    }
}
