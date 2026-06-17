<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\CandleData;
use App\DataTransferObjects\QuoteData;
use App\Enums\Market;
use App\Enums\Timeframe;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real market-data integration against the Finnhub REST API.
 *
 * @see https://finnhub.io/docs/api
 *
 * This is intentionally non-blocking for user requests: it is only ever called
 * from the scheduled FetchStockPricesJob, never inside an HTTP request cycle.
 */
class FinnhubProvider implements MarketDataProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://finnhub.io/api/v1',
    ) {}

    public function key(): string
    {
        return 'finnhub';
    }

    public function getQuote(Stock $stock): ?QuoteData
    {
        $response = Http::baseUrl($this->baseUrl)
            ->timeout(8)
            ->retry(2, 200)
            ->get('/quote', [
                'symbol' => $this->vendorSymbol($stock),
                'token' => $this->apiKey,
            ]);

        if ($response->failed()) {
            Log::warning('Finnhub quote failed', ['symbol' => $stock->symbol, 'status' => $response->status()]);

            return null;
        }

        $data = $response->json();

        if (! isset($data['c']) || (float) $data['c'] === 0.0) {
            return null;
        }

        return new QuoteData(
            symbol: $stock->symbol,
            price: (float) $data['c'],
            open: (float) ($data['o'] ?? $data['c']),
            high: (float) ($data['h'] ?? $data['c']),
            low: (float) ($data['l'] ?? $data['c']),
            previousClose: (float) ($data['pc'] ?? $data['c']),
            volume: 0.0,
            at: isset($data['t']) ? CarbonImmutable::createFromTimestamp((int) $data['t']) : CarbonImmutable::now(),
        );
    }

    public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array
    {
        $resolution = $this->resolution($timeframe);
        $to = CarbonImmutable::now();
        $from = $to->subSeconds($timeframe->seconds() * $limit);

        $response = Http::baseUrl($this->baseUrl)
            ->timeout(10)
            ->retry(2, 200)
            ->get('/stock/candle', [
                'symbol' => $this->vendorSymbol($stock),
                'resolution' => $resolution,
                'from' => $from->getTimestamp(),
                'to' => $to->getTimestamp(),
                'token' => $this->apiKey,
            ]);

        $data = $response->json();

        if (! is_array($data) || ($data['s'] ?? null) !== 'ok') {
            return [];
        }

        $candles = [];
        $count = count($data['t'] ?? []);

        for ($i = 0; $i < $count; $i++) {
            $candles[] = new CandleData(
                timeframe: $timeframe,
                open: (float) $data['o'][$i],
                high: (float) $data['h'][$i],
                low: (float) $data['l'][$i],
                close: (float) $data['c'][$i],
                volume: (float) ($data['v'][$i] ?? 0),
                at: CarbonImmutable::createFromTimestamp((int) $data['t'][$i]),
            );
        }

        return $candles;
    }

    /**
     * Finnhub expects an exchange suffix for non-US markets (BIST → ".IS").
     */
    private function vendorSymbol(Stock $stock): string
    {
        return $stock->market === Market::BIST
            ? "{$stock->symbol}.IS"
            : $stock->symbol;
    }

    private function resolution(Timeframe $timeframe): string
    {
        return match ($timeframe) {
            Timeframe::OneMinute => '1',
            Timeframe::FiveMinutes => '5',
            Timeframe::FifteenMinutes => '15',
            Timeframe::OneHour => '60',
            Timeframe::OneDay => 'D',
        };
    }
}
