<?php

declare(strict_types=1);

use App\Enums\ProviderType;
use App\Enums\Timeframe;
use App\Models\ApiProvider;
use App\Models\Stock;
use App\Models\User;
use App\Services\MarketData\MarketDataProviderInterface;
use App\Services\MarketData\TwelveDataProvider;
use App\Services\Providers\ApiProviderRegistry;
use Database\Seeders\ApiProviderSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('excludes synthetic providers when a real provider is active and keeps priority order', function () {
    ApiProvider::factory()->create([
        'key' => 'synthetic',
        'type' => ProviderType::MarketData,
        'priority' => 1,
        'is_active' => true,
    ]);
    ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'priority' => 20,
        'is_active' => true,
        'api_key' => 'finnhub-key',
    ]);
    ApiProvider::factory()->create([
        'key' => 'twelvedata',
        'type' => ProviderType::MarketData,
        'priority' => 10,
        'is_active' => true,
        'api_key' => 'twelvedata-key',
    ]);

    expect(app(ApiProviderRegistry::class)->activeProviderKeys(ProviderType::MarketData))
        ->toBe(['twelvedata', 'finnhub']);
});

it('does not treat keyless API providers as configured real providers', function () {
    ApiProvider::factory()->create([
        'key' => 'synthetic',
        'type' => ProviderType::MarketData,
        'priority' => 1,
        'is_active' => true,
    ]);
    ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'priority' => 20,
        'is_active' => true,
        'api_key' => null,
    ]);

    $registry = app(ApiProviderRegistry::class);

    expect($registry->hasActiveRealProvider(ProviderType::MarketData))->toBeFalse()
        ->and($registry->activeProviderKeys(ProviderType::MarketData))->toBe(['synthetic', 'finnhub']);
});

it('seeds missing API providers without reactivating disabled providers', function () {
    ApiProvider::factory()->create([
        'key' => 'synthetic',
        'type' => ProviderType::MarketData,
        'is_active' => false,
    ]);

    $this->seed(ApiProviderSeeder::class);

    expect(ApiProvider::query()->where('key', 'synthetic')->firstOrFail()->is_active)->toBeFalse()
        ->and(ApiProvider::query()->where('key', 'fmp')->exists())->toBeTrue()
        ->and(ApiProvider::query()->where('key', 'rss')->exists())->toBeTrue();
});

it('allows admins to update provider refresh settings', function () {
    $admin = User::factory()->admin()->create();
    $provider = ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'refresh_interval_minutes' => 5,
        'fetch_limit' => 50,
    ]);

    $this->actingAs($admin)
        ->put("/admin/providers/{$provider->id}", [
            'key' => $provider->key,
            'name' => $provider->name,
            'type' => $provider->type->value,
            'is_active' => false,
            'priority' => 15,
            'refresh_interval_minutes' => 15,
            'fetch_limit' => 75,
        ])
        ->assertRedirect();

    $provider->refresh();

    // Disabling routes through the state machine, so status becomes "disabled".
    expect($provider->is_active)->toBeFalse()
        ->and($provider->priority)->toBe(15)
        ->and($provider->status->value)->toBe('disabled')
        ->and($provider->refresh_interval_minutes)->toBe(15)
        ->and($provider->fetch_limit)->toBe(75);
});

it('stores provider API keys encrypted and hides plaintext from admin props', function () {
    $admin = User::factory()->admin()->create();
    $provider = ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'api_key' => 'plain-secret',
    ]);

    $rawApiKey = DB::table('api_providers')->where('id', $provider->id)->value('api_key');

    expect($rawApiKey)->not->toBe('plain-secret')
        ->and($provider->fresh()->api_key)->toBe('plain-secret');

    $this->actingAs($admin)
        ->get('/admin/providers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/ApiProviders')
            ->where('providers.0.api_key_configured', true)
            ->missing('providers.0.api_key'));
});

it('preserves provider API keys on blank update and clears them only with the clear flag', function () {
    $admin = User::factory()->admin()->create();
    $provider = ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'api_key' => 'original-secret',
    ]);

    $payload = [
        'key' => $provider->key,
        'name' => $provider->name,
        'type' => $provider->type->value,
        'api_key' => '',
        'clear_api_key' => false,
        'is_active' => true,
        'auto_sync_stocks' => false,
        'auto_recovery' => true,
        'priority' => 25,
        'refresh_interval_minutes' => 10,
        'fetch_limit' => 25,
    ];

    $this->actingAs($admin)->put("/admin/providers/{$provider->id}", $payload)->assertRedirect();

    expect($provider->fresh()->api_key)->toBe('original-secret');

    $this->actingAs($admin)->put("/admin/providers/{$provider->id}", array_merge($payload, ['clear_api_key' => true]))->assertRedirect();

    expect($provider->fresh()->api_key)->toBeNull();
});

it('returns empty without throwing when Twelve Data is rate-limited (429)', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.twelvedata.com/*' => Http::response(
            ['code' => 429, 'message' => 'You have run out of API credits for the day.'],
            429,
        ),
    ]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);
    $provider = new TwelveDataProvider('test-key');

    expect($provider->getQuote($stock))->toBeNull()
        ->and($provider->getCandles($stock, Timeframe::OneDay, 150))->toBe([]);
});

it('uses the DB provider key when constructing market data providers', function () {
    config(['tradenews.market_data.providers.finnhub.key' => 'env-token']);

    ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'base_url' => 'https://finnhub.io/api/v1',
        'api_key' => 'db-token',
        'is_active' => true,
        'capabilities' => ['quotes'],
    ]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    Http::preventStrayRequests();
    Http::fake([
        'finnhub.io/api/v1/quote*' => Http::response(['c' => 101, 'o' => 100, 'h' => 102, 'l' => 99, 'pc' => 98, 't' => now()->timestamp], 200),
    ]);

    $providers = app(ApiProviderRegistry::class)->marketDataProviders();

    expect($providers)->toHaveCount(1);

    $providers[0]->getQuote($stock);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'token=db-token')
        && ! str_contains($request->url(), 'token=env-token'));
});

it('does not fall back to configured synthetic when database providers are disabled', function () {
    config(['tradenews.market_data.default' => 'synthetic']);

    ApiProvider::factory()->create([
        'key' => 'synthetic',
        'type' => ProviderType::MarketData,
        'is_active' => false,
    ]);

    expect(app(MarketDataProviderInterface::class)->key())->toBe('none');
});
