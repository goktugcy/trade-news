<?php

declare(strict_types=1);

use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Models\Stock;
use App\Services\MarketData\FmpQuoteSyncService;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\Providers\ProviderHealthService;
use App\Services\Sync\FmpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
});

function fmpQuoteService(): FmpQuoteSyncService
{
    return new FmpQuoteSyncService(
        new FmpClient('test-key', 'https://financialmodelingprep.com/stable', 'NASDAQ'),
        app(ProviderHealthService::class),
    );
}

function seedFmpQuoteProvider(): ApiProvider
{
    return ApiProvider::factory()->create([
        'key' => 'fmp',
        'type' => ProviderType::MarketData,
        'is_active' => true,
        'status' => ProviderStatus::Unknown,
        'markets' => ['NASDAQ'],
        'capabilities' => ['list', 'profiles', 'quotes'],
        'api_key' => 'test-key',
        'base_url' => 'https://financialmodelingprep.com/stable',
    ]);
}

function indexMember(string $symbol): Stock
{
    $stock = Stock::factory()->nasdaq()->create(['symbol' => $symbol, 'currency' => 'USD']);
    $stock->indexMemberships()->create(['index_key' => 'nasdaq100', 'is_current' => true]);

    return $stock;
}

it('syncs latest quotes for index members via the FMP batch endpoint', function () {
    seedFmpQuoteProvider();
    $aapl = indexMember('AAPL');

    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/stable/batch-quote*' => Http::response([
            [
                'symbol' => 'AAPL', 'price' => 195.5, 'previousClose' => 190.0, 'open' => 191.0,
                'dayHigh' => 196.0, 'dayLow' => 190.5, 'volume' => 1234567, 'avgVolume' => 1000000,
                'timestamp' => 1750000000,
            ],
        ], 200),
    ]);

    $synced = fmpQuoteService()->sync();

    $cached = MarketDataIngestor::cachedQuote($aapl->id);

    expect($synced)->toBe(1)
        ->and($cached)->not->toBeNull()
        ->and($cached['price'])->toBe(195.5)
        ->and($cached['previous_close'])->toBe(190.0)
        ->and($cached['average_volume'])->toBe(1000000.0)
        ->and($cached['provider_key'])->toBe('fmp')
        // Persisted locally as a quote candle so latestPrice keeps working.
        ->and($aapl->prices()->where('source_kind', 'quote')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stable/batch-quote')
        && str_contains($request->url(), 'symbols=AAPL'));
});

it('records provider failure when the FMP batch quote request errors', function () {
    $provider = seedFmpQuoteProvider();
    indexMember('AAPL');

    Http::preventStrayRequests();
    Http::fake(['financialmodelingprep.com/stable/batch-quote*' => Http::response('rate limited', 429)]);

    $synced = fmpQuoteService()->sync();

    expect($synced)->toBe(0)
        ->and($provider->fresh()->consecutive_failures)->toBeGreaterThan(0)
        ->and($provider->fresh()->last_error)->not->toBeNull();
});

it('marks the provider operational again after a successful batch quote', function () {
    $provider = seedFmpQuoteProvider();
    $provider->update(['status' => ProviderStatus::Degraded, 'consecutive_failures' => 3, 'auto_recovery' => true]);
    indexMember('AAPL');

    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/stable/batch-quote*' => Http::response([
            ['symbol' => 'AAPL', 'price' => 200, 'previousClose' => 198],
        ], 200),
    ]);

    // Recovery needs `recover_after` (2) consecutive successful runs.
    fmpQuoteService()->sync();
    fmpQuoteService()->sync();

    expect($provider->fresh()->status)->toBe(ProviderStatus::Operational);
});

it('does not fetch FMP quotes for stocks outside NASDAQ-100 / S&P 500', function () {
    seedFmpQuoteProvider();
    // Active, but not a current index member.
    Stock::factory()->nasdaq()->create(['symbol' => 'ZZZZ']);

    Http::preventStrayRequests();
    Http::fake();

    $synced = fmpQuoteService()->sync();

    expect($synced)->toBe(0);
    Http::assertNothingSent();
});
