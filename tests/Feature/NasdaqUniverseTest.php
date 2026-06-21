<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\ProviderType;
use App\Enums\StockSignal;
use App\Models\ApiProvider;
use App\Models\NewsItem;
use App\Models\NewsStockMatch;
use App\Models\Stock;
use App\Models\StockAiAnalysis;
use App\Models\StockAlert;
use App\Models\StockPrice;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\Notification\NotificationCenter;
use App\Services\Providers\ProviderHealthService;
use App\Services\Sync\FmpClient;
use App\Services\Sync\NasdaqSyncService;
use App\Services\Sync\UsIndexUniverseService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();

    config([
        'tradenews.sync.us_universe.source' => 'fallback',
        'tradenews.sync.us_universe.min_sp500_symbols' => 2,
        'tradenews.sync.us_universe.min_nasdaq100_symbols' => 2,
        'us_index_universe.fallback.sp500' => ['AAPL MSFT BRK-B'],
        'us_index_universe.fallback.nasdaq100' => ['NVDA TSLA'],
    ]);
});

it('reports prunable NASDAQ rows without deleting them in dry run mode', function () {
    $kept = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);
    $outside = Stock::factory()->nasdaq()->create(['symbol' => 'ZZZZ']);
    $bist = Stock::factory()->bist()->create(['symbol' => 'ZZZZ']);

    $this->artisan('tradenews:prune-nasdaq-universe --dry-run --source=fallback')
        ->assertSuccessful();

    expect(Stock::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Stock::query()->whereKey($outside->id)->exists())->toBeTrue()
        ->and(Stock::query()->whereKey($bist->id)->exists())->toBeTrue();
});

it('deletes only NASDAQ rows outside the configured index universe and cascades related data', function () {
    $kept = Stock::factory()->nasdaq()->create(['symbol' => 'MSFT']);
    $shareClass = Stock::factory()->nasdaq()->create(['symbol' => 'BRK.B']);
    $outside = Stock::factory()->nasdaq()->create(['symbol' => 'ZZZZ']);
    $bist = Stock::factory()->bist()->create(['symbol' => 'ZZZZ']);
    $user = User::factory()->create();
    $news = NewsItem::factory()->create();

    StockPrice::factory()->create(['stock_id' => $outside->id]);
    NewsStockMatch::query()->create([
        'news_item_id' => $news->id,
        'stock_id' => $outside->id,
        'match_type' => 'symbol',
        'matched_term' => 'ZZZZ',
        'confidence' => 1,
    ]);
    Watchlist::factory()->create(['user_id' => $user->id, 'stock_id' => $outside->id]);
    StockAlert::factory()->create(['user_id' => $user->id, 'stock_id' => $outside->id]);
    StockAiAnalysis::query()->create([
        'stock_id' => $outside->id,
        'signal' => StockSignal::Neutral,
        'confidence' => 50,
        'summary' => 'Temporary analysis',
        'generated_at' => now(),
    ]);

    $this->artisan('tradenews:prune-nasdaq-universe --source=fallback')
        ->assertSuccessful();

    expect(Stock::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Stock::query()->whereKey($shareClass->id)->exists())->toBeTrue()
        ->and(Stock::query()->whereKey($bist->id)->exists())->toBeTrue()
        ->and(Stock::query()->whereKey($outside->id)->exists())->toBeFalse()
        ->and(StockPrice::query()->where('stock_id', $outside->id)->exists())->toBeFalse()
        ->and(NewsStockMatch::query()->where('stock_id', $outside->id)->exists())->toBeFalse()
        ->and(Watchlist::query()->where('stock_id', $outside->id)->exists())->toBeFalse()
        ->and(StockAlert::query()->where('stock_id', $outside->id)->exists())->toBeFalse()
        ->and(StockAiAnalysis::query()->where('stock_id', $outside->id)->exists())->toBeFalse();
});

it('resolves the live FMP universe from S&P 500 constituents and QQQ holdings', function () {
    config(['tradenews.sync.us_universe.source' => 'fmp']);

    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/stable/sp500-constituent*' => Http::response([
            ['symbol' => 'AAPL'],
            ['symbol' => 'BRK.B'],
            ['symbol' => 'MSFT'],
        ]),
        'financialmodelingprep.com/stable/etf/holdings*' => Http::response([
            'holdings' => [
                ['symbol' => 'NVDA'],
                ['holdingSymbol' => 'TSLA'],
                ['asset' => 'GOOGL'],
                ['asset' => 'Apple Inc.'],
            ],
        ]),
    ]);

    $result = (new UsIndexUniverseService(new FmpClient('test-key')))
        ->resolve('fmp', forceLive: true);

    expect($result['source'])->toBe('fmp')
        ->and($result['sp500_count'])->toBe(3)
        ->and($result['nasdaq100_count'])->toBe(3)
        ->and($result['symbols'])->toContain('AAPL', 'BRK-B', 'GOOGL', 'MSFT', 'NVDA', 'TSLA')
        ->and($result['symbols'])->not->toContain('APPLE');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stable/sp500-constituent'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stable/etf/holdings')
        && str_contains($request->url(), 'symbol=QQQ'));
});

it('falls back to the static universe when live FMP universe endpoints are unavailable', function () {
    config(['tradenews.sync.us_universe.source' => 'auto']);

    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/*' => Http::response('Restricted Endpoint', 402),
    ]);

    $result = (new UsIndexUniverseService(new FmpClient('test-key')))
        ->resolve('auto', forceLive: true);

    expect($result['source'])->toBe('fallback')
        ->and($result['fallback_reason'] ?? null)->not->toBeNull()
        ->and($result['symbols'])->toContain('AAPL', 'MSFT', 'NVDA', 'TSLA');
});

it('filters FMP NASDAQ list sync to the allowed index universe', function () {
    Http::preventStrayRequests();
    Http::fake([
        'financialmodelingprep.com/stable/stock-list*' => Http::response([
            ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true],
            ['symbol' => 'NVDA', 'name' => 'NVIDIA Corp', 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true],
            ['symbol' => 'ZZZZ', 'name' => 'Outside Corp', 'exchangeShortName' => 'NASDAQ', 'isActivelyTrading' => true],
        ]),
    ]);

    $fmp = new FmpClient('test-key');
    $run = (new NasdaqSyncService(
        $fmp,
        new UsIndexUniverseService($fmp),
        app(ProviderHealthService::class),
        app(NotificationCenter::class),
    ))->syncList();

    expect($run->status)->toBe('success')
        ->and($run->created_count)->toBe(2)
        ->and($run->meta['skipped_by_universe'] ?? null)->toBe(1)
        ->and(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'AAPL')->exists())->toBeTrue()
        ->and(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'NVDA')->exists())->toBeTrue()
        ->and(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'ZZZZ')->exists())->toBeFalse();
});

it('filters the Finnhub fallback NASDAQ sync to the allowed index universe', function () {
    ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'base_url' => 'https://finnhub.io/api/v1',
        'api_key' => 'test-token',
        'is_active' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'finnhub.io/api/v1/stock/symbol*' => Http::response([
            ['symbol' => 'AAPL', 'displaySymbol' => 'AAPL', 'description' => 'APPLE INC', 'type' => 'Common Stock', 'mic' => 'XNAS'],
            ['symbol' => 'MSFT', 'displaySymbol' => 'MSFT', 'description' => 'MICROSOFT CORP', 'type' => 'Common Stock', 'mic' => 'XNYS'],
            ['symbol' => 'ZZZZ', 'displaySymbol' => 'ZZZZ', 'description' => 'OUTSIDE CORP', 'type' => 'Common Stock', 'mic' => 'XNAS'],
        ]),
    ]);

    $this->artisan('tradenews:sync-nasdaq-stocks')
        ->assertSuccessful();

    expect(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'AAPL')->exists())->toBeTrue()
        ->and(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'MSFT')->exists())->toBeTrue()
        ->and(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'ZZZZ')->exists())->toBeFalse();
});
