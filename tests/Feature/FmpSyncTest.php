<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\ProviderType;
use App\Jobs\FetchStockPricesJob;
use App\Models\ApiProvider;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\SyncRun;
use App\Models\SystemJob;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use App\Services\Providers\ProviderHealthService;
use App\Services\Sync\FmpClient;
use App\Services\Sync\NasdaqSyncService;
use App\Services\Sync\UsIndexUniverseService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Cache::flush();

    config(['tradenews.sync.us_universe.source' => 'fallback']);
});

function fmpService(): NasdaqSyncService
{
    $fmp = new FmpClient('test-key', 'https://financialmodelingprep.com/api/v3', 'NASDAQ');

    return new NasdaqSyncService(
        $fmp,
        new UsIndexUniverseService($fmp),
        app(ProviderHealthService::class),
        app(NotificationCenter::class),
    );
}

function seedFmpProvider(array $attributes = []): ApiProvider
{
    return ApiProvider::factory()->create(array_merge([
        'key' => 'fmp',
        'type' => ProviderType::MarketData,
        'is_active' => true,
        'markets' => ['NASDAQ'],
        'capabilities' => ['list', 'profiles'],
        'api_key' => 'test-key',
        'base_url' => 'https://financialmodelingprep.com/stable',
    ], $attributes));
}

it('syncs the NASDAQ list and records a successful run', function () {
    seedFmpProvider();
    Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']); // existing → counts as updated

    Http::fake([
        'financialmodelingprep.com/stable/stock-list*' => Http::response([
            ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true],
            ['symbol' => 'NVDA', 'name' => 'NVIDIA Corp', 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true],
            ['symbol' => 'BABA', 'name' => 'Alibaba Group', 'exchangeShortName' => 'NYSE', 'isActivelyTrading' => true],
        ], 200),
    ]);

    $run = fmpService()->syncList();

    expect($run->status)->toBe(SyncRun::STATUS_SUCCESS)
        ->and($run->created_count)->toBe(1)   // NVDA
        ->and($run->updated_count)->toBe(1)   // AAPL
        ->and(Stock::where('symbol', 'NVDA')->where('market', 'NASDAQ')->exists())->toBeTrue();
});

it('syncs company profiles and fills metadata', function () {
    seedFmpProvider();
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL', 'profile_synced_at' => null]);

    Http::fake([
        'financialmodelingprep.com/stable/profile*' => Http::response([[
            'symbol' => 'AAPL', 'sector' => 'Technology', 'industry' => 'Consumer Electronics',
            'mktCap' => 3000000000000, 'website' => 'https://apple.com', 'description' => 'Maker of iPhone.',
            'image' => 'https://img/aapl.png',
        ]], 200),
    ]);

    $run = fmpService()->syncProfiles(10);

    $stock->refresh();
    expect($run->status)->toBe(SyncRun::STATUS_SUCCESS)
        ->and($stock->industry)->toBe('Consumer Electronics')
        ->and($stock->market_cap)->toBe(3000000000000.0)
        ->and($stock->profile_synced_at)->not->toBeNull();
});

it('records a failed run and notifies admins when FMP errors', function () {
    seedFmpProvider();
    $admin = User::factory()->admin()->create();
    Http::fake(['financialmodelingprep.com/*' => Http::response('rate limited', 429)]);

    $run = fmpService()->syncList();

    expect($run->status)->toBe(SyncRun::STATUS_FAILED)
        ->and($admin->userNotifications()->where('category', 'sync')->count())->toBeGreaterThan(0);
});

it('falls back to the Finnhub command when no FMP key is set', function () {
    ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'base_url' => 'https://finnhub.io/api/v1',
        'api_key' => 'test',
        'is_active' => true,
    ]);

    Http::fake([
        'finnhub.io/*' => Http::response([
            ['symbol' => 'AAPL', 'description' => 'Apple Inc.', 'type' => 'Common Stock', 'mic' => 'XNAS'],
        ], 200),
    ]);

    $this->artisan('tradenews:sync-nasdaq')->assertExitCode(0);
});

it('skips profile sync without an FMP key', function () {
    // FMP key is empty in phpunit.xml.
    $this->artisan('tradenews:sync-profiles')->expectsOutputToContain('skipping')->assertExitCode(0);
});

it('runs the FMP list sync when the provider capability interval is due', function () {
    $provider = seedFmpProvider([
        'refresh_interval_minutes' => 1,
        'meta' => [
            'last_fetched_at_by_capability' => [
                'list' => now()->subMinutes(2)->toISOString(),
            ],
        ],
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/stable/stock-list*' => Http::response([
            ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true],
        ], 200),
    ]);

    $this->artisan('tradenews:sync-nasdaq')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stable/stock-list'));

    expect($provider->fresh()->meta['last_fetched_at_by_capability']['list'] ?? null)->not->toBeNull();
});

it('falls back to Finnhub when the FMP stable list endpoint is restricted', function () {
    ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'base_url' => 'https://finnhub.io/api/v1',
        'api_key' => 'finnhub-key',
        'is_active' => true,
    ]);

    seedFmpProvider(['refresh_interval_minutes' => 1]);

    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/stable/stock-list*' => Http::response('Restricted Endpoint: unavailable under current subscription', 402),
        'finnhub.io/*' => Http::response([
            ['symbol' => 'AAPL', 'displaySymbol' => 'AAPL', 'description' => 'Apple Inc.', 'type' => 'Common Stock', 'mic' => 'XNAS'],
        ], 200),
    ]);

    $this->artisan('tradenews:sync-nasdaq')->assertSuccessful();

    $run = SyncRun::query()->where('type', 'nasdaq_list')->latest('id')->first();
    $job = SystemJob::query()->where('name', 'tradenews:sync-nasdaq')->latest('id')->first();

    expect($run?->status)->toBe(SyncRun::STATUS_SUCCESS)
        ->and($run?->meta['skipped'] ?? null)->toBe('fmp_list_endpoint_unavailable')
        ->and($job?->meta['fallback_provider'] ?? null)->toBe('finnhub')
        ->and(Stock::query()->where('symbol', 'AAPL')->where('market', 'NASDAQ')->exists())->toBeTrue();
});

it('skips the FMP list sync when the provider capability interval is not due', function () {
    seedFmpProvider([
        'refresh_interval_minutes' => 60,
        'meta' => [
            'last_fetched_at_by_capability' => [
                'list' => now()->toISOString(),
            ],
        ],
    ]);

    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('tradenews:sync-nasdaq')->assertSuccessful();

    Http::assertNothingSent();

    $job = SystemJob::query()->where('name', 'tradenews:sync-nasdaq')->latest('id')->first();

    expect($job?->meta['skipped'] ?? null)->toBe('not_due');
});

it('uses the provider fetch limit for due FMP profile syncs', function () {
    $provider = seedFmpProvider([
        'refresh_interval_minutes' => 1,
        'fetch_limit' => 1,
        'meta' => [
            'last_fetched_at_by_capability' => [
                'profiles' => now()->subMinutes(2)->toISOString(),
            ],
        ],
    ]);

    Stock::factory()->nasdaq()->create(['symbol' => 'AAPL', 'profile_synced_at' => null]);
    Stock::factory()->nasdaq()->create(['symbol' => 'MSFT', 'profile_synced_at' => null]);

    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/stable/profile*' => Http::response([[
            'symbol' => 'AAPL',
            'sector' => 'Technology',
            'industry' => 'Consumer Electronics',
        ]], 200),
    ]);

    $this->artisan('tradenews:sync-profiles')->assertSuccessful();

    Http::assertSentCount(1);

    expect($provider->fresh()->meta['last_fetched_at_by_capability']['profiles'] ?? null)->not->toBeNull();
});

it('does not treat FMP as a price provider without quote or candle capabilities', function () {
    Queue::fake();

    $provider = seedFmpProvider([
        'last_fetched_at' => null,
        'refresh_interval_minutes' => 1,
    ]);

    Stock::factory()->nasdaq()->count(3)->create();

    $this->artisan('tradenews:fetch-prices --market=NASDAQ')->assertSuccessful();

    Queue::assertNotPushed(FetchStockPricesJob::class);

    $job = SystemJob::query()->where('name', 'tradenews:fetch-prices')->latest('id')->first();

    expect($provider->fresh()->last_fetched_at)->toBeNull()
        ->and($job?->meta['provider_keys'] ?? null)->toBe([])
        ->and($job?->meta['skipped'] ?? null)->toBe('no_active_price_provider');
});

it('does not run market auto-sync when provider auto-sync is disabled', function () {
    seedFmpProvider([
        'auto_sync_stocks' => false,
        'refresh_interval_minutes' => 1,
    ]);

    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('tradenews:sync-market-stocks --force')->assertSuccessful();

    Http::assertNothingSent();

    $job = SystemJob::query()->where('name', 'tradenews:sync-market-stocks')->latest('id')->first();

    expect($job?->meta['provider_keys'] ?? null)->toBe([])
        ->and($job?->meta['skipped'] ?? null)->toBe('no_auto_sync_providers');
});

it('runs FMP NASDAQ list sync from the generic market auto-sync command', function () {
    $provider = seedFmpProvider([
        'auto_sync_stocks' => true,
        'capabilities' => ['list'],
        'refresh_interval_minutes' => 1,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/stable/stock-list*' => Http::response([
            ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true],
        ], 200),
    ]);

    $this->artisan('tradenews:sync-market-stocks')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stable/stock-list')
        && str_contains($request->url(), 'apikey=test-key'));

    $job = SystemJob::query()->where('name', 'tradenews:sync-market-stocks')->latest('id')->first();

    expect(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'AAPL')->exists())->toBeTrue()
        ->and($provider->fresh()->meta['last_fetched_at_by_capability']['list'] ?? null)->not->toBeNull()
        ->and($job?->meta['provider_keys'] ?? null)->toBe(['fmp']);
});

it('uses the configured FMP key for generic market auto-sync when the provider row key is blank', function () {
    config(['tradenews.sync.fmp.key' => 'config-fmp-key']);

    seedFmpProvider([
        'api_key' => null,
        'auto_sync_stocks' => true,
        'capabilities' => ['list'],
        'refresh_interval_minutes' => 1,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/stable/stock-list*' => Http::response([
            ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true],
        ], 200),
    ]);

    $this->artisan('tradenews:sync-market-stocks --force')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stable/stock-list')
        && str_contains($request->url(), 'apikey=config-fmp-key'));

    expect(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'AAPL')->exists())->toBeTrue();
});

it('syncs BIST100 stocks and quote prices through RapidAPI auto-sync', function () {
    ApiProvider::factory()->create([
        'key' => 'rapidapi-bist100',
        'name' => 'RapidAPI BIST100',
        'type' => ProviderType::MarketData,
        'base_url' => 'https://bist100-stock-data-15-minutes-late-live.p.rapidapi.com',
        'api_key' => 'rapid-key',
        'is_active' => true,
        'auto_sync_stocks' => true,
        'markets' => ['BIST'],
        'capabilities' => ['list', 'quotes'],
        'refresh_interval_minutes' => 1,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'bist100-stock-data-15-minutes-late-live.p.rapidapi.com/bist100/prices' => Http::response([
            [
                'code' => 'AKBNK',
                'open' => 78.3,
                'close' => 81.15,
                'high' => 81.5,
                'low' => 78.1,
                'last' => 81.15,
                'daily_change_price' => 3.15,
                'daily_change_percent' => 4.04,
                'volume_turkish_lira' => 10900403136.45,
                'volume_lot' => 135821755,
                'volatility' => 4.36,
                'last_update' => '18.06.2026 18:14:42',
            ],
        ], 200),
    ]);

    $this->artisan('tradenews:sync-market-stocks')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bist100/prices')
        && $request->hasHeader('x-rapidapi-key', 'rapid-key'));

    $stock = Stock::query()->where('market', Market::BIST->value)->where('symbol', 'AKBNK')->firstOrFail();
    $price = StockPrice::query()
        ->where('stock_id', $stock->id)
        ->where('provider_key', 'rapidapi-bist100')
        ->where('timeframe', '1d')
        ->firstOrFail();

    expect($stock->currency)->toBe('TRY')
        ->and((float) $price->open)->toBe(78.3)
        ->and((float) $price->high)->toBe(81.5)
        ->and((float) $price->low)->toBe(78.1)
        ->and((float) $price->close)->toBe(81.15)
        ->and((float) $price->volume)->toBe(135821755.0)
        ->and($price->price_at->format('Y-m-d H:i'))->toBe('2026-06-18 00:00')
        ->and($price->source_kind)->toBe(StockPrice::SOURCE_QUOTE);
});

it('runs RapidAPI BIST100 sync for an aliased provider key even when FMP is skipped', function () {
    seedFmpProvider([
        'api_key' => null,
        'auto_sync_stocks' => true,
        'refresh_interval_minutes' => 1,
    ]);

    ApiProvider::factory()->create([
        'key' => 'rapid',
        'name' => 'RapidAPI',
        'type' => ProviderType::MarketData,
        'base_url' => 'https://bist100-stock-data-15-minutes-late-live.p.rapidapi.com/bist100/prices',
        'api_key' => 'rapid-key',
        'is_active' => true,
        'auto_sync_stocks' => true,
        'markets' => ['BIST'],
        'capabilities' => ['list', 'profiles'],
        'refresh_interval_minutes' => 20,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'bist100-stock-data-15-minutes-late-live.p.rapidapi.com/bist100/prices' => Http::response([
            [
                'code' => 'AKBNK',
                'open' => 78.3,
                'close' => 81.15,
                'high' => 81.5,
                'low' => 78.1,
                'last' => 81.15,
                'daily_change_price' => 3.15,
                'volume_lot' => 135821755,
                'last_update' => '18.06.2026 18:14:42',
            ],
        ], 200),
    ]);

    $this->artisan('tradenews:sync-market-stocks --force')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/bist100/prices')
        && ! str_contains($request->url(), '/bist100/prices/bist100/prices'));

    $job = SystemJob::query()->where('name', 'tradenews:sync-market-stocks')->latest('id')->first();
    $run = SyncRun::query()->where('type', 'bist100_quotes')->latest('id')->first();
    $stock = Stock::query()->where('market', Market::BIST->value)->where('symbol', 'AKBNK')->firstOrFail();
    $price = StockPrice::query()->where('stock_id', $stock->id)->where('provider_key', 'rapidapi-bist100')->firstOrFail();

    expect((float) $price->close)->toBe(81.15)
        ->and($run?->provider_key)->toBe('rapid')
        ->and($job?->meta['provider_keys'] ?? null)->toBe(['rapid'])
        ->and($job?->meta['skipped'][0]['provider_key'] ?? null)->toBe('fmp')
        ->and($job?->meta['skipped'][0]['reason'] ?? null)->toBe('missing_api_key');
});

it('does not run NASDAQ sync when FMP is scoped to BIST', function () {
    seedFmpProvider([
        'auto_sync_stocks' => true,
        'markets' => ['BIST'],
        'capabilities' => ['list'],
    ]);

    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('tradenews:sync-market-stocks --force')->assertSuccessful();

    Http::assertNothingSent();

    $job = SystemJob::query()->where('name', 'tradenews:sync-market-stocks')->latest('id')->first();

    expect($job?->meta['provider_keys'] ?? null)->toBe([])
        ->and($job?->meta['skipped'][0]['reason'] ?? null)->toBe('market_not_selected');
});

it('renders the admin sync logs and system notification pages', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/sync-logs')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/SyncLogs')->has('runs')->has('summary'));

    $this->actingAs($admin)->get('/admin/system-notifications')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/SystemNotifications')->has('logs'));
});
