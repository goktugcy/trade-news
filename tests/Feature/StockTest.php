<?php

declare(strict_types=1);

use App\Enums\ProviderType;
use App\Enums\Timeframe;
use App\Models\ApiProvider;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
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

it('shows a stock detail page bound by symbol with market status and alert options', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    $this->actingAs($user)
        ->get('/stocks/AAPL')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('stocks/Show')
            ->where('stock.symbol', 'AAPL')
            ->where('stock.tradingview_symbol', 'NASDAQ:AAPL')
            ->has('marketStatus.session_label')
            ->has('alertTypes')
            ->has('stockAlerts')
            ->has('recentActivity'));
});

it('creates a stock alert from the detail page and lists it', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    $this->actingAs($user)
        ->post('/alerts/stock', [
            'stock_id' => $stock->id,
            'type' => 'price_above',
            'threshold' => 250,
        ])
        ->assertRedirect();

    expect($user->stockAlerts()->where('stock_id', $stock->id)->where('type', 'price_above')->exists())->toBeTrue();

    $this->actingAs($user)
        ->get('/stocks/AAPL')
        ->assertInertia(fn (Assert $page) => $page->component('stocks/Show')->has('stockAlerts', 1));
});

it('renders the admin market data monitoring page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/market-data')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/MarketDataMonitoring')
            ->has('providers')
            ->has('freshness')
            ->has('index_counts.nasdaq100'));
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
});

it('searches stocks by symbol or name as JSON', function () {
    $user = User::factory()->create();
    Stock::factory()->create(['symbol' => 'TSLA', 'name' => 'Tesla Inc.']);

    $this->actingAs($user)
        ->getJson('/stocks/search?q=tesla')
        ->assertOk()
        ->assertJsonPath('results.0.symbol', 'TSLA');
});
