<?php

declare(strict_types=1);

use App\Enums\Timeframe;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

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
            ->where('stock.symbol', 'AAPL'));
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

it('searches stocks by symbol or name as JSON', function () {
    $user = User::factory()->create();
    Stock::factory()->create(['symbol' => 'TSLA', 'name' => 'Tesla Inc.']);

    $this->actingAs($user)
        ->getJson('/stocks/search?q=tesla')
        ->assertOk()
        ->assertJsonPath('results.0.symbol', 'TSLA');
});
