<?php

use App\Enums\Timeframe;
use App\Models\Stock;
use App\Services\MarketData\FinnhubProvider;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\News\FinnhubNewsProvider;
use Illuminate\Support\Facades\Http;

it('caches a quote when finnhub candles are forbidden', function () {
    Http::preventStrayRequests();

    Http::fake([
        'finnhub.io/api/v1/stock/candle*' => Http::response([
            'error' => "You don't have access to this resource.",
        ], 403),
        'finnhub.io/api/v1/quote*' => Http::response([
            'c' => 150.25,
            'o' => 149.00,
            'h' => 151.00,
            'l' => 148.50,
            'pc' => 148.75,
            't' => 1_700_000_000,
        ]),
    ]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);
    $provider = new FinnhubProvider('test-token', candlesEnabled: true);

    (new MarketDataIngestor($provider))->sync($stock);

    expect(MarketDataIngestor::cachedQuote($stock->id))
        ->toMatchArray([
            'symbol' => 'AAPL',
            'price' => 150.25,
            'previous_close' => 148.75,
            'change' => 1.5,
        ]);

    $this->assertDatabaseHas('stock_prices', [
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::FiveMinutes->value,
        'close' => '150.250000',
    ]);
    $this->assertDatabaseHas('stock_prices', [
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::OneDay->value,
        'close' => '150.250000',
    ]);
});

it('returns an empty news list when finnhub news is forbidden', function () {
    Http::preventStrayRequests();

    Http::fake([
        'finnhub.io/api/v1/news*' => Http::response([
            'error' => "You don't have access to this resource.",
        ], 403),
    ]);

    $provider = new FinnhubNewsProvider('test-token');

    expect($provider->fetchLatest())->toBe([]);
});
