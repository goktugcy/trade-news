<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Enums\StockIndex;
use App\Models\ApiProvider;
use App\Models\Stock;
use App\Models\StockIndexMembership;
use App\Services\Notification\NotificationCenter;
use App\Services\Providers\ProviderHealthService;
use App\Services\Sync\FmpClient;
use App\Services\Sync\NasdaqSyncService;
use App\Services\Sync\UsIndexUniverseService;
use Database\Seeders\UsIndexUniverseSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();

    config([
        'tradenews.sync.us_universe.source' => 'fmp',
        'tradenews.sync.us_universe.min_sp500_symbols' => 1,
        'tradenews.sync.us_universe.min_nasdaq100_symbols' => 1,
    ]);
});

function indexFmpService(): NasdaqSyncService
{
    $fmp = new FmpClient('test-key', 'https://financialmodelingprep.com/stable', 'NASDAQ');

    return new NasdaqSyncService(
        $fmp,
        new UsIndexUniverseService($fmp),
        app(ProviderHealthService::class),
        app(NotificationCenter::class),
    );
}

/**
 * @param  array<int, string>  $stockList
 * @param  array<int, string>  $sp500
 * @param  array<int, string>  $nasdaq100
 */
function fakeFmpUniverse(array $stockList, array $sp500, array $nasdaq100): void
{
    Http::fake([
        'financialmodelingprep.com/stable/stock-list*' => Http::response(
            array_map(fn (string $s): array => [
                'symbol' => $s, 'name' => "{$s} Inc.", 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true,
            ], $stockList),
            200,
        ),
        'financialmodelingprep.com/stable/sp500-constituent*' => Http::response(
            array_map(fn (string $s): array => ['symbol' => $s], $sp500),
            200,
        ),
        'financialmodelingprep.com/stable/etf/holdings*' => Http::response(
            array_map(fn (string $s): array => ['symbol' => $s], $nasdaq100),
            200,
        ),
    ]);
}

it('writes per-index membership and a stored tradingview symbol on sync', function () {
    fakeFmpUniverse(
        stockList: ['AAPL', 'MSFT', 'NVDA', 'COST'],
        sp500: ['AAPL', 'MSFT'],
        nasdaq100: ['AAPL', 'NVDA', 'COST'],
    );

    indexFmpService()->syncList();

    $aapl = Stock::query()->where('symbol', 'AAPL')->firstOrFail();

    // AAPL is in BOTH indices → one stock row, two current memberships.
    expect($aapl->tradingview_symbol)->toBe('NASDAQ:AAPL')
        ->and($aapl->is_active)->toBeTrue()
        ->and(collect($aapl->currentIndexKeys())->sort()->values()->all())->toBe(['nasdaq100', 'sp500'])
        ->and(StockIndexMembership::query()->where('stock_id', $aapl->id)->count())->toBe(2);

    expect(Stock::query()->where('symbol', 'MSFT')->firstOrFail()->currentIndexKeys())->toBe(['sp500'])
        ->and(Stock::query()->where('symbol', 'NVDA')->firstOrFail()->currentIndexKeys())->toBe(['nasdaq100']);

    // added_at stamped when joining.
    expect(StockIndexMembership::query()->where('stock_id', $aapl->id)->whereNull('added_at')->count())->toBe(0);
});

it('retires removed constituents and deactivates stocks dropped from every index', function () {
    // One fake whose NASDAQ-100 list mutates by reference (calling Http::fake
    // twice would stack stubs and the first registration would always win).
    $nasdaq100 = ['AAPL', 'NVDA', 'COST'];
    Http::fake([
        'financialmodelingprep.com/stable/stock-list*' => Http::response(
            array_map(fn (string $s): array => [
                'symbol' => $s, 'name' => "{$s} Inc.", 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true,
            ], ['AAPL', 'NVDA', 'COST']),
            200,
        ),
        'financialmodelingprep.com/stable/sp500-constituent*' => Http::response([['symbol' => 'AAPL']], 200),
        'financialmodelingprep.com/stable/etf/holdings*' => function () use (&$nasdaq100) {
            return Http::response(array_map(fn (string $s): array => ['symbol' => $s], $nasdaq100), 200);
        },
    ]);

    indexFmpService()->syncList();

    Cache::flush();

    // COST leaves NASDAQ-100 (and is in no other index); NVDA stays.
    $nasdaq100 = ['AAPL', 'NVDA'];
    indexFmpService()->syncList();

    $cost = Stock::query()->where('symbol', 'COST')->firstOrFail();
    $membership = StockIndexMembership::query()
        ->where('stock_id', $cost->id)->where('index_key', 'nasdaq100')->firstOrFail();

    expect($membership->is_current)->toBeFalse()
        ->and($membership->removed_at)->not->toBeNull()
        ->and($cost->fresh()->is_active)->toBeFalse()   // dropped from all indices → inactive (not deleted)
        ->and(Stock::query()->where('symbol', 'NVDA')->firstOrFail()->is_active)->toBeTrue();
});

it('filters stocks by current index membership via the scope', function () {
    fakeFmpUniverse(
        stockList: ['AAPL', 'MSFT', 'NVDA'],
        sp500: ['AAPL', 'MSFT'],
        nasdaq100: ['AAPL', 'NVDA'],
    );
    indexFmpService()->syncList();

    expect(Stock::query()->inIndex(StockIndex::Nasdaq100)->pluck('symbol')->sort()->values()->all())->toBe(['AAPL', 'NVDA'])
        ->and(Stock::query()->inIndex('sp500')->pluck('symbol')->sort()->values()->all())->toBe(['AAPL', 'MSFT']);
});

it('maps class-share symbols to a dotted tradingview ticker', function () {
    // FMP delivers BRK.B; we normalize to BRK-B internally but TradingView wants BRK.B.
    expect(Stock::tradingViewSymbolFor(Market::NASDAQ, 'BRK-B'))->toBe('NASDAQ:BRK.B')
        ->and(Stock::tradingViewSymbolFor(Market::BIST, 'THYAO'))->toBe('BIST:THYAO');

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL', 'tradingview_symbol' => null]);
    expect($stock->tradingViewSymbol())->toBe('NASDAQ:AAPL');
});

it('seeds the NASDAQ-100 + S&P 500 universe with index memberships', function () {
    $this->seed(UsIndexUniverseSeeder::class);

    expect(Stock::query()->where('market', 'NASDAQ')->where('is_active', true)->count())->toBeGreaterThan(450)
        ->and(StockIndexMembership::query()->where('index_key', 'nasdaq100')->where('is_current', true)->count())->toBeGreaterThan(90)
        ->and(StockIndexMembership::query()->where('index_key', 'sp500')->where('is_current', true)->count())->toBeGreaterThan(450)
        ->and(Stock::query()->where('symbol', 'AAPL')->first()?->tradingview_symbol)->toBe('NASDAQ:AAPL')
        ->and(Stock::query()->inIndex(StockIndex::Nasdaq100)->where('symbol', 'AAPL')->exists())->toBeTrue();
});

it('tracks daily request/failure counters and rolling latency, resetting on day rollover', function () {
    ApiProvider::factory()->create([
        'key' => 'fmp',
        'type' => ProviderType::MarketData,
        'is_active' => true,
        'status' => ProviderStatus::Operational,
        'auto_recovery' => true,
    ]);

    $health = app(ProviderHealthService::class);

    $health->recordSuccess('fmp', 'health_check', 100);
    $health->recordSuccess('fmp', 'health_check', 200);
    $health->recordFailure('fmp', 'boom');

    $provider = ApiProvider::query()->where('key', 'fmp')->firstOrFail();

    expect($provider->daily_request_count)->toBe(3)
        ->and($provider->daily_failure_count)->toBe(1)
        ->and($provider->avg_latency_ms)->toBe(130); // 100 then round(100*0.7 + 200*0.3)

    // Day rollover resets the running totals.
    $provider->forceFill(['daily_counts_on' => now()->subDay()])->save();
    $health->recordSuccess('fmp', 'health_check', 50);

    $provider->refresh();
    expect($provider->daily_request_count)->toBe(1)
        ->and($provider->daily_failure_count)->toBe(0);
});
