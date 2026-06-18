<?php

declare(strict_types=1);

use App\DataTransferObjects\QuoteData;
use App\Enums\ProviderType;
use App\Enums\Timeframe;
use App\Jobs\FetchStockPricesJob;
use App\Models\ApiProvider;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\MarketData\MarketDataProviderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function dueMarketProvider(): void
{
    ApiProvider::factory()->create([
        'key' => 'synthetic',
        'type' => ProviderType::MarketData,
        'is_active' => true,
        'last_fetched_at' => null,
    ]);
}

it('dispatches only stocks without a fresh price', function () {
    Queue::fake();
    dueMarketProvider();

    $fresh = Stock::factory()->nasdaq()->create(['symbol' => 'FRSH']);
    StockPrice::factory()->create(['stock_id' => $fresh->id]); // created_at = now → fresh

    $stale = Stock::factory()->nasdaq()->create(['symbol' => 'STAL']); // never priced

    $this->artisan('tradenews:fetch-prices')->assertExitCode(0);

    Queue::assertPushed(FetchStockPricesJob::class, fn (FetchStockPricesJob $j) => $j->stockId === $stale->id);
    Queue::assertNotPushed(FetchStockPricesJob::class, fn (FetchStockPricesJob $j) => $j->stockId === $fresh->id);
});

it('dispatches fresh stocks too when forced', function () {
    Queue::fake();
    dueMarketProvider();

    $fresh = Stock::factory()->nasdaq()->create();
    StockPrice::factory()->create(['stock_id' => $fresh->id]);

    $this->artisan('tradenews:fetch-prices --force')->assertExitCode(0);

    Queue::assertPushed(FetchStockPricesJob::class, fn (FetchStockPricesJob $j) => $j->stockId === $fresh->id && $j->force);
});

it('skips the provider call inside the job when the stock is already fresh', function () {
    $stock = Stock::factory()->create();
    StockPrice::factory()->create(['stock_id' => $stock->id]); // fresh

    $provider = new class implements MarketDataProviderInterface
    {
        public bool $called = false;

        public function key(): string
        {
            return 'spy';
        }

        public function getQuote(Stock $stock): ?QuoteData
        {
            $this->called = true;

            return null;
        }

        public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array
        {
            $this->called = true;

            return [];
        }
    };

    (new FetchStockPricesJob($stock->id))->handle($provider);

    expect($provider->called)->toBeFalse();
});

it('fetches inside the job when the only price is stale', function () {
    $stock = Stock::factory()->create();
    $price = StockPrice::factory()->create(['stock_id' => $stock->id]);
    // created_at isn't fillable; force it into the past so the stock reads stale.
    StockPrice::query()->whereKey($price->id)->update(['created_at' => CarbonImmutable::now()->subHour()]);

    $provider = new class implements MarketDataProviderInterface
    {
        public bool $called = false;

        public function key(): string
        {
            return 'spy';
        }

        public function getQuote(Stock $stock): ?QuoteData
        {
            $this->called = true;

            return null;
        }

        public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array
        {
            $this->called = true;

            return [];
        }
    };

    (new FetchStockPricesJob($stock->id))->handle($provider);

    expect($provider->called)->toBeTrue();
});
