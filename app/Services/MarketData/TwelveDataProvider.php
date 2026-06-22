<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\CandleData;
use App\DataTransferObjects\QuoteData;
use App\Enums\Timeframe;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        try {
            $response = $this->client(8)->get('/quote', [
                'symbol' => $stock->symbol,
                'apikey' => $this->apiKey,
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Twelve Data quote connection failed', [
                'symbol' => $stock->symbol,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Twelve Data quote failed', [
                'symbol' => $stock->symbol,
                'status' => $response->status(),
                'body' => str($response->body())->limit(160)->toString(),
            ]);

            return null;
        }

        $data = $response->json();

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
        try {
            $response = $this->client(10)->get('/time_series', [
                'symbol' => $stock->symbol,
                'interval' => $this->interval($timeframe),
                'outputsize' => $limit,
                'apikey' => $this->apiKey,
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Twelve Data candle connection failed', [
                'symbol' => $stock->symbol,
                'timeframe' => $timeframe->value,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('Twelve Data candle failed', [
                'symbol' => $stock->symbol,
                'timeframe' => $timeframe->value,
                'status' => $response->status(),
                'body' => str($response->body())->limit(160)->toString(),
            ]);

            return [];
        }

        $data = $response->json();
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

    /**
     * Build a client that never throws on HTTP errors: a failed/rate-limited
     * (429) response is returned so callers handle it gracefully instead of the
     * exception bubbling up and being reported on every scheduler tick.
     */
    private function client(int $timeout): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->connectTimeout(3)
            ->timeout($timeout)
            ->retry(
                [200, 500],
                when: fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                throw: false,
            );
    }
}
