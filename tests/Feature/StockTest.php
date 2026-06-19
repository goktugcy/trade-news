<?php

declare(strict_types=1);

use App\DataTransferObjects\QuoteData;
use App\Enums\ProviderType;
use App\Enums\Timeframe;
use App\Models\ApiProvider;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\MarketData\MarketDataProviderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
});

it('lists stocks for authenticated users', function () {
    $user = User::factory()->create();
    Stock::factory()->count(4)->create();

    $this->actingAs($user)
        ->get('/stocks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('stocks/Index')->has('stocks', 4));
});

it('shows a stock detail page bound by symbol', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);

    $this->actingAs($user)
        ->get('/stocks/AAPL')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('stocks/Show')
            ->where('stock.symbol', 'AAPL')
            ->has('chartRanges'));
});

it('returns candle JSON for the chart', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'NVDA']);
    StockPrice::factory()->count(10)->create([
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::FiveMinutes,
    ]);

    $this->actingAs($user)
        ->getJson('/stocks/NVDA/candles?timeframe=5m')
        ->assertOk()
        ->assertJsonPath('symbol', 'NVDA')
        ->assertJsonCount(10, 'candles');
});

it('hides synthetic and legacy candles from chart JSON when a real API provider is active', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'AMD']);
    ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'is_active' => true,
        'api_key' => 'test-key',
    ]);

    StockPrice::factory()->create([
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::FiveMinutes,
        'provider_key' => 'finnhub',
        'source_kind' => StockPrice::SOURCE_CANDLE,
        'price_at' => CarbonImmutable::createFromTimestamp(1_700_000_000),
    ]);
    StockPrice::factory()->synthetic()->create([
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::FiveMinutes,
        'price_at' => CarbonImmutable::createFromTimestamp(1_700_000_300),
    ]);
    StockPrice::factory()->legacy()->create([
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::FiveMinutes,
        'price_at' => CarbonImmutable::createFromTimestamp(1_700_000_600),
    ]);

    $this->actingAs($user)
        ->getJson('/stocks/AMD/candles?timeframe=5m')
        ->assertOk()
        ->assertJsonCount(1, 'candles')
        ->assertJsonPath('candles.0.provider_key', 'finnhub')
        ->assertJsonPath('source.api_active', true);
});

it('does not show synthetic prices when the synthetic provider is disabled', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->bist()->create(['symbol' => 'AKBNK']);

    ApiProvider::factory()->create([
        'key' => 'synthetic',
        'type' => ProviderType::MarketData,
        'is_active' => false,
    ]);

    StockPrice::factory()->synthetic()->create([
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::OneDay,
        'close' => 382.20,
        'price_at' => CarbonImmutable::create(2026, 6, 18, 13, 25, 31),
    ]);

    $this->actingAs($user)
        ->get('/stocks?market=BIST')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('stocks/Index')
            ->where('stocks.0.symbol', 'AKBNK')
            ->where('stocks.0.price', null));

    $this->actingAs($user)
        ->getJson('/stocks/AKBNK/candles?timeframe=1d')
        ->assertOk()
        ->assertJsonCount(0, 'candles')
        ->assertJsonPath('source.synthetic_hidden', true);
});

it('returns the latest 300 chart candles in ascending time order', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'INTC']);
    $base = CarbonImmutable::createFromTimestamp(1_700_000_000);

    for ($i = 0; $i < 305; $i++) {
        StockPrice::factory()->create([
            'stock_id' => $stock->id,
            'timeframe' => Timeframe::FiveMinutes,
            'price_at' => $base->addMinutes($i * 5),
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson('/stocks/INTC/candles?timeframe=5m')
        ->assertOk()
        ->assertJsonCount(300, 'candles');

    $candles = $response->json('candles');

    expect($candles[0]['time'])->toBe($base->addMinutes(25)->getTimestamp())
        ->and($candles[299]['time'])->toBe($base->addMinutes(1_520)->getTimestamp());
});

it('returns expanded daily candle ranges for yearly chart selections', function () {
    $this->travelTo(CarbonImmutable::create(2026, 6, 19, 16));

    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    $now = CarbonImmutable::now();

    StockPrice::factory()->create([
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::OneDay,
        'price_at' => $now->subDays(370),
    ]);

    for ($i = 364; $i >= 0; $i--) {
        StockPrice::factory()->create([
            'stock_id' => $stock->id,
            'timeframe' => Timeframe::OneDay,
            'price_at' => $now->subDays($i),
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson('/stocks/AAPL/candles?timeframe=1d&range=1y')
        ->assertOk()
        ->assertJsonPath('range', '1y')
        ->assertJsonCount(365, 'candles');

    $candles = $response->json('candles');

    expect($candles[0]['time'])->toBe($now->subDays(364)->getTimestamp())
        ->and($candles[364]['time'])->toBe($now->getTimestamp());
});

it('includes a cached quote candle in chart JSON', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'MSFT']);

    (new MarketDataIngestor(new class implements MarketDataProviderInterface
    {
        public function key(): string
        {
            return 'fake';
        }

        public function getQuote(Stock $stock): ?QuoteData
        {
            return null;
        }

        public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array
        {
            return [];
        }
    }))->cacheQuote($stock, new QuoteData(
        symbol: 'MSFT',
        price: 420.50,
        open: 418.00,
        high: 421.00,
        low: 417.50,
        previousClose: 417.00,
        volume: 0,
        at: CarbonImmutable::createFromTimestamp(1_700_000_000),
    ));

    $this->actingAs($user)
        ->getJson('/stocks/MSFT/candles?timeframe=5m')
        ->assertOk()
        ->assertJsonPath('candles.0.close', 420.50);
});

it('searches stocks by symbol or name as JSON', function () {
    $user = User::factory()->create();
    Stock::factory()->create(['symbol' => 'TSLA', 'name' => 'Tesla Inc.']);

    $this->actingAs($user)
        ->getJson('/stocks/search?q=tesla')
        ->assertOk()
        ->assertJsonPath('results.0.symbol', 'TSLA');
});
