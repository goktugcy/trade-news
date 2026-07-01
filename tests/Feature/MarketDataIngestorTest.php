<?php

declare(strict_types=1);

use App\DataTransferObjects\QuoteData;
use App\Enums\Timeframe;
use App\Models\Stock;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\MarketData\MarketDataProviderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

/**
 * In-memory provider that counts candle requests so we can assert whether the
 * historical-OHLCV sync ran.
 */
class SpyMarketDataProvider implements MarketDataProviderInterface
{
    public int $candleCalls = 0;

    public int $quoteCalls = 0;

    public function key(): string
    {
        return 'spy';
    }

    public function getQuote(Stock $stock): ?QuoteData
    {
        $this->quoteCalls++;

        return new QuoteData('AAPL', 195.0, 191.0, 196.0, 190.0, 190.0, 1000.0, CarbonImmutable::now());
    }

    public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array
    {
        $this->candleCalls++;

        return [];
    }
}

it('skips historical candle sync when the OHLCV flag is disabled (default)', function () {
    config(['tradenews.chart.historical_ohlcv_enabled' => false]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL', 'currency' => 'USD']);
    $provider = new SpyMarketDataProvider;

    (new MarketDataIngestor($provider))->sync($stock);

    expect($provider->candleCalls)->toBe(0)
        ->and($provider->quoteCalls)->toBe(1)
        // Quote is still cached + persisted so charts/quotes keep working.
        ->and(MarketDataIngestor::cachedQuote($stock->id))->not->toBeNull();
});

it('fetches historical candles when the OHLCV flag is enabled', function () {
    config(['tradenews.chart.historical_ohlcv_enabled' => true]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL', 'currency' => 'USD']);
    $provider = new SpyMarketDataProvider;

    (new MarketDataIngestor($provider))->sync($stock);

    // One getCandles call per SYNC_TIMEFRAMES entry (5m + 1d).
    expect($provider->candleCalls)->toBe(count(MarketDataIngestor::SYNC_TIMEFRAMES));
});
