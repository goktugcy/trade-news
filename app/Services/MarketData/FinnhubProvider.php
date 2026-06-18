<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\CandleData;
use App\DataTransferObjects\QuoteData;
use App\Enums\Market;
use App\Enums\Timeframe;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        private readonly bool $candlesEnabled = false,
    ) {}

    public function key(): string
    {
        return 'finnhub';
    }

    public function getQuote(Stock $stock): ?QuoteData
    {
        try {
            $response = $this->client(8)->get('/quote', [
                'symbol' => $this->vendorSymbol($stock),
                'token' => $this->apiKey,
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Finnhub quote connection failed', [
                'symbol' => $stock->symbol,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Finnhub quote failed', [
                'symbol' => $stock->symbol,
                'status' => $response->status(),
                'body' => str($response->body())->limit(160)->toString(),
            ]);

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
        if (! $this->candlesEnabled) {
            return [];
        }

        $resolution = $this->resolution($timeframe);
        $to = CarbonImmutable::now();
        $from = $to->subSeconds($timeframe->seconds() * $limit);

        try {
            $response = $this->client(10)->get('/stock/candle', [
                'symbol' => $this->vendorSymbol($stock),
                'resolution' => $resolution,
                'from' => $from->getTimestamp(),
                'to' => $to->getTimestamp(),
                'token' => $this->apiKey,
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Finnhub candle connection failed', [
                'symbol' => $stock->symbol,
                'timeframe' => $timeframe->value,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('Finnhub candle failed', [
                'symbol' => $stock->symbol,
                'timeframe' => $timeframe->value,
                'status' => $response->status(),
                'body' => str($response->body())->limit(160)->toString(),
            ]);

            return [];
        }

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

    private function client(int $timeout): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->connectTimeout(3)
            ->timeout($timeout)
            ->retry(
                [200, 500],
                when: fn (Throwable $exception): bool => $this->shouldRetry($exception),
                throw: false,
            );
    }

    private function shouldRetry(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
    }
}
