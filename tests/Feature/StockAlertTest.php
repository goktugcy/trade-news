<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\Timeframe;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\Stock;
use App\Models\StockAlert;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\Alerts\AlertEvaluator;
use App\Services\MarketData\MarketDataIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function cacheQuote(Stock $stock, float $price, float $changePercent = 0, float $volume = 0): void
{
    Cache::put(MarketDataIngestor::quoteCacheKey($stock->id), [
        'price' => $price, 'change_percent' => $changePercent, 'volume' => $volume,
        'at' => now()->toIso8601String(),
    ], 300);
}

it('fires a price-above alert and creates an in-app notification', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    cacheQuote($stock, price: 210);
    StockAlert::factory()->for($user)->for($stock)->type(AlertType::PriceAbove, 200)->create();

    $fired = app(AlertEvaluator::class)->evaluateAll();

    expect($fired)->toBe(1)
        ->and($user->userNotifications()->count())->toBe(1);
});

it('does not fire when the condition is not met', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create();
    cacheQuote($stock, price: 180);
    StockAlert::factory()->for($user)->for($stock)->type(AlertType::PriceAbove, 200)->create();

    expect(app(AlertEvaluator::class)->evaluateAll())->toBe(0);
});

it('respects the cooldown window', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create();
    cacheQuote($stock, price: 210);
    StockAlert::factory()->for($user)->for($stock)->type(AlertType::PriceAbove, 200)->create([
        'cooldown_minutes' => 60,
        'last_triggered_at' => now()->subMinutes(10),
    ]);

    expect(app(AlertEvaluator::class)->evaluateAll())->toBe(0);
});

it('fires percent-change and volume alerts', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create();
    cacheQuote($stock, price: 100, changePercent: 6.2, volume: 5_000_000);

    StockAlert::factory()->for($user)->for($stock)->type(AlertType::PercentChange, 5)->create();
    StockAlert::factory()->for($user)->for(Stock::factory()->create())->type(AlertType::VolumeIncrease, 1_000_000)->create([
        'stock_id' => $stock->id,
    ]);

    expect(app(AlertEvaluator::class)->evaluateAll())->toBe(2);
});

it('fires a daily-gain alert only on an upward move', function () {
    $user = User::factory()->create();
    $up = Stock::factory()->create(['symbol' => 'UP']);
    $down = Stock::factory()->create(['symbol' => 'DN']);
    cacheQuote($up, price: 100, changePercent: 6.0);
    cacheQuote($down, price: 100, changePercent: -6.0);

    StockAlert::factory()->for($user)->for($up)->type(AlertType::PercentUp, 5)->create();
    StockAlert::factory()->for($user)->for($down)->type(AlertType::PercentUp, 5)->create();

    // Only the +6% stock fires; the -6% stock does not.
    expect(app(AlertEvaluator::class)->evaluateAll())->toBe(1);
});

it('fires a daily-drop alert only on a downward move', function () {
    $user = User::factory()->create();
    $down = Stock::factory()->create(['symbol' => 'DN']);
    $up = Stock::factory()->create(['symbol' => 'UP']);
    cacheQuote($down, price: 100, changePercent: -6.0);
    cacheQuote($up, price: 100, changePercent: 6.0);

    StockAlert::factory()->for($user)->for($down)->type(AlertType::PercentDown, 5)->create();
    StockAlert::factory()->for($user)->for($up)->type(AlertType::PercentDown, 5)->create();

    expect(app(AlertEvaluator::class)->evaluateAll())->toBe(1);
});

it('increments the trigger count each time an alert fires', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    cacheQuote($stock, price: 210);
    $alert = StockAlert::factory()->for($user)->for($stock)->type(AlertType::PriceAbove, 200)->create([
        'cooldown_minutes' => 0,
    ]);

    app(AlertEvaluator::class)->evaluateAll();
    app(AlertEvaluator::class)->evaluateAll();

    expect($alert->fresh()->trigger_count)->toBe(2)
        ->and($alert->fresh()->last_triggered_at)->not->toBeNull();
});

it('fires a news-detected alert for newly matched news', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create();
    $source = NewsSource::factory()->create(['is_active' => true]);
    $news = NewsItem::factory()->create([
        'source_id' => $source->id,
        'is_matched' => true,
        'published_at' => now()->subMinutes(5),
        'importance_score' => 70,
    ]);
    $news->stocks()->attach($stock->id, ['match_type' => 'symbol', 'matched_term' => $stock->symbol, 'confidence' => 1, 'created_at' => now()]);

    StockAlert::factory()->for($user)->for($stock)->type(AlertType::ImportantNews, 50)->create([
        'last_triggered_at' => now()->subDay(),
    ]);

    expect(app(AlertEvaluator::class)->evaluateAll())->toBe(1)
        ->and($user->userNotifications()->where('category', 'alert')->count())->toBe(1);
});

it('lets a user create and delete a stock alert', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create();

    $this->actingAs($user)->post('/alerts/stock', [
        'stock_id' => $stock->id,
        'type' => 'price_below',
        'threshold' => 150,
        'cooldown_minutes' => 30,
        'notify_in_app' => true,
    ])->assertRedirect();

    $alert = $user->stockAlerts()->first();
    expect($alert)->not->toBeNull()->and($alert->type)->toBe(AlertType::PriceBelow);

    $this->actingAs($user)->delete("/alerts/stock/{$alert->id}")->assertRedirect();
    expect(StockAlert::find($alert->id))->toBeNull();
});

it('requires a threshold for price alerts but not for news-detected', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create();

    $this->actingAs($user)->from('/alerts')
        ->post('/alerts/stock', ['stock_id' => $stock->id, 'type' => 'price_above'])
        ->assertSessionHasErrors('threshold');

    $this->actingAs($user)
        ->post('/alerts/stock', ['stock_id' => $stock->id, 'type' => 'news_detected'])
        ->assertRedirect();
});

it('forbids editing another user alert', function () {
    $alert = StockAlert::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->delete("/alerts/stock/{$alert->id}")
        ->assertForbidden();
});

// keep StockPrice/Timeframe imports meaningful for static analysis
it('falls back to the latest stored candle when no cached quote exists', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create();
    StockPrice::factory()->create([
        'stock_id' => $stock->id, 'timeframe' => Timeframe::FiveMinutes,
        'close' => 250, 'volume' => 9_000_000, 'price_at' => CarbonImmutable::now(),
    ]);
    StockAlert::factory()->for($user)->for($stock)->type(AlertType::VolumeIncrease, 1_000_000)->create();

    expect(app(AlertEvaluator::class)->evaluateAll())->toBe(1);
});
