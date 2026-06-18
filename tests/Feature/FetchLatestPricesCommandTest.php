<?php

use App\Enums\ProviderType;
use App\Jobs\FetchStockPricesJob;
use App\Models\ApiProvider;
use App\Models\Stock;
use Illuminate\Support\Facades\Queue;

it('limits dispatched price fetch jobs', function () {
    Queue::fake();

    ApiProvider::factory()->create([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'is_active' => true,
        'priority' => 10,
        'last_fetched_at' => null,
        'api_key' => 'test-key',
    ]);

    Stock::factory()->nasdaq()->count(5)->create();

    $this->artisan('tradenews:fetch-prices --market=NASDAQ --limit=2')
        ->assertSuccessful();

    Queue::assertPushed(FetchStockPricesJob::class, 2);
});
