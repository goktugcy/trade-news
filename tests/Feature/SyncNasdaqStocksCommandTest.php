<?php

use App\Enums\Market;
use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Models\Stock;
use Illuminate\Support\Facades\Http;

it('syncs nasdaq stock symbols from finnhub', function () {
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
            [
                'currency' => 'USD',
                'description' => 'MICROSOFT CORP',
                'displaySymbol' => 'MSFT',
                'mic' => 'XNAS',
                'symbol' => 'MSFT',
                'type' => 'Common Stock',
            ],
            [
                'currency' => 'USD',
                'description' => 'NASDAQ ETF',
                'displaySymbol' => 'QQQ',
                'mic' => 'XNAS',
                'symbol' => 'QQQ',
                'type' => 'ETP',
            ],
            [
                'currency' => 'USD',
                'description' => 'NEW YORK STOCK',
                'displaySymbol' => 'NYSE',
                'mic' => 'XNYS',
                'symbol' => 'NYSE',
                'type' => 'Common Stock',
            ],
        ]),
    ]);

    $this->artisan('tradenews:sync-nasdaq-stocks')
        ->assertSuccessful();

    $stock = Stock::query()
        ->where('market', Market::NASDAQ->value)
        ->where('symbol', 'MSFT')
        ->firstOrFail();

    expect($stock->name)->toBe('MICROSOFT CORP')
        ->and($stock->aliases)->toBe(['MSFT', 'MICROSOFT CORP'])
        ->and(Stock::query()->where('symbol', 'QQQ')->exists())->toBeFalse()
        ->and(Stock::query()->where('symbol', 'NYSE')->exists())->toBeFalse();
});
