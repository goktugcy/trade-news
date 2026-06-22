<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Timeframe;
use App\Jobs\ImportStooqHistoryJob;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\MarketData\HistoricalPriceImportService;
use App\Services\MarketData\ImportSyncLogger;
use App\Services\MarketData\StooqClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Support\SessionKey;

function stooqCsv(array $rows): string
{
    return implode("\n", array_merge(['Date,Open,High,Low,Close,Volume'], $rows));
}

it('builds the daily download URL with the .us suffix and date window', function () {
    Http::fake([
        'stooq.com/*' => Http::response(stooqCsv(['2026-06-18,10,12,9,11,1000'])),
    ]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    $csv = app(StooqClient::class)->fetchDailyCsv($stock, CarbonImmutable::parse('2020-06-18'));

    expect($csv)->toContain('2026-06-18,10,12,9,11,1000');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/q/d/l/')
            && $request['s'] === 'aapl.us'
            && $request['i'] === 'd'
            && $request['d1'] === '20200618';
    });
});

it('maps dotted NASDAQ symbols to dashed stooq tickers', function () {
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'BRK.B']);

    expect(app(StooqClient::class)->stooqSymbol($stock))->toBe('brk-b.us');
});

it('returns null for non-NASDAQ stocks', function () {
    $stock = Stock::factory()->create(['market' => Market::BIST, 'symbol' => 'AKBNK']);

    expect(app(StooqClient::class)->stooqSymbol($stock))->toBeNull()
        ->and(app(StooqClient::class)->fetchDailyCsv($stock))->toBeNull();
});

it('returns null when stooq responds with no data or HTML', function () {
    Http::fake([
        'stooq.com/*' => Http::response('No data'),
    ]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    expect(app(StooqClient::class)->fetchDailyCsv($stock))->toBeNull();

    Http::fake([
        'stooq.com/*' => Http::response('<html><body>error</body></html>'),
    ]);

    expect(app(StooqClient::class)->fetchDailyCsv($stock))->toBeNull();
});

it('imports a daily CSV as 1d candles', function () {
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    $result = app(HistoricalPriceImportService::class)->importDailyCsv($stock, stooqCsv([
        '2026-06-17,9,11,8,10,900',
        '2026-06-18,10,12,9,11,1000',
    ]));

    expect($result['imported'])->toBe(2)
        ->and($result['created'])->toBe(2);

    $price = StockPrice::query()->where('stock_id', $stock->id)->orderBy('price_at')->first();

    expect($price->timeframe)->toBe(Timeframe::OneDay)
        ->and($price->provider_key)->toBe(StockPrice::PROVIDER_STOOQ_API)
        ->and($price->close)->toBe(10.0)
        ->and($price->price_at->toDateString())->toBe('2026-06-17');
});

it('upserts duplicate daily candles instead of creating duplicates', function () {
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);
    $importer = app(HistoricalPriceImportService::class);

    $importer->importDailyCsv($stock, stooqCsv(['2026-06-18,10,12,9,11,1000']));
    $second = $importer->importDailyCsv($stock, stooqCsv(['2026-06-18,10,12,9,12,1500']));

    expect($second['updated'])->toBe(1)
        ->and(StockPrice::query()->where('stock_id', $stock->id)->count())->toBe(1);

    $price = StockPrice::query()->where('stock_id', $stock->id)->firstOrFail();

    expect($price->close)->toBe(12.0);
});

it('dispatches a job per active NASDAQ stock', function () {
    Queue::fake();

    Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);
    Stock::factory()->nasdaq()->create(['symbol' => 'MSFT']);
    Stock::factory()->nasdaq()->create(['symbol' => 'OLD', 'is_active' => false]);
    Stock::factory()->create(['market' => Market::BIST, 'symbol' => 'AKBNK']);

    $this->artisan('tradenews:import-stooq-history')->assertSuccessful();

    Queue::assertPushed(ImportStooqHistoryJob::class, 2);
});

it('imports inline with the --sync flag', function () {
    Http::fake([
        'stooq.com/*' => Http::response(stooqCsv(['2026-06-18,10,12,9,11,1000'])),
    ]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    $this->artisan('tradenews:import-stooq-history --sync')->assertSuccessful();

    expect(StockPrice::query()->where('stock_id', $stock->id)->where('provider_key', StockPrice::PROVIDER_STOOQ_API)->count())->toBe(1);
});

it('skips inactive and non-NASDAQ stocks in the job', function () {
    Http::fake([
        'stooq.com/*' => Http::response(stooqCsv(['2026-06-18,10,12,9,11,1000'])),
    ]);

    $bist = Stock::factory()->create(['market' => Market::BIST, 'symbol' => 'AKBNK']);

    (new ImportStooqHistoryJob($bist->id))->handle(app(StooqClient::class), app(HistoricalPriceImportService::class));

    expect(StockPrice::query()->count())->toBe(0);
});

it('queues jobs for all NASDAQ stocks from the admin endpoint', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);
    Stock::factory()->nasdaq()->create(['symbol' => 'MSFT']);

    $this->actingAs($admin)
        ->post('/admin/stocks/historical-prices/stooq/fetch')
        ->assertRedirect();

    Queue::assertPushed(ImportStooqHistoryJob::class, 2);
});

it('fetches and imports a single stock synchronously from the admin endpoint', function () {
    Http::fake([
        'stooq.com/*' => Http::response(stooqCsv(['2026-06-18,10,12,9,11,1000'])),
    ]);

    $admin = User::factory()->admin()->create();
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    $this->actingAs($admin)
        ->post("/admin/stocks/{$stock->id}/historical-prices/stooq/fetch")
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.imported', 1);

    expect(StockPrice::query()->where('stock_id', $stock->id)->where('timeframe', Timeframe::OneDay->value)->exists())->toBeTrue();
});

it('flashes an error when fetching a single non-NASDAQ stock', function () {
    $admin = User::factory()->admin()->create();
    $stock = Stock::factory()->create(['market' => Market::BIST, 'symbol' => 'AKBNK']);

    $this->actingAs($admin)
        ->post("/admin/stocks/{$stock->id}/historical-prices/stooq/fetch")
        ->assertRedirect();

    expect(StockPrice::query()->count())->toBe(0);
});

it('blocks non-admin users from the stooq fetch endpoints', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    $this->actingAs($user)->post('/admin/stocks/historical-prices/stooq/fetch')->assertForbidden();
    $this->actingAs($user)->post("/admin/stocks/{$stock->id}/historical-prices/stooq/fetch")->assertForbidden();
});

it('logs a successful sync run with the symbol when the job imports candles', function () {
    Http::fake([
        'stooq.com/*' => Http::response(stooqCsv(['2026-06-18,10,12,9,11,1000'])),
    ]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    (new ImportStooqHistoryJob($stock->id))->handle(app(StooqClient::class), app(HistoricalPriceImportService::class));

    $run = SyncRun::query()->where('type', ImportSyncLogger::TYPE_STOOQ_HISTORY)->latest('id')->firstOrFail();

    expect($run->status)->toBe(SyncRun::STATUS_SUCCESS)
        ->and($run->created_count)->toBe(1)
        ->and($run->meta['symbol'])->toBe('AAPL');
});

it('logs an empty sync run noting the block when stooq returns no data', function () {
    Http::fake([
        'stooq.com/*' => Http::response('<html>JavaScript required</html>'),
    ]);

    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    (new ImportStooqHistoryJob($stock->id))->handle(app(StooqClient::class), app(HistoricalPriceImportService::class));

    $run = SyncRun::query()->where('type', ImportSyncLogger::TYPE_STOOQ_HISTORY)->latest('id')->firstOrFail();

    expect($run->status)->toBe(SyncRun::STATUS_SUCCESS)
        ->and($run->processed)->toBe(0)
        ->and($run->meta['symbol'])->toBe('AAPL')
        ->and($run->meta['note'])->toContain('no data');
});

it('logs a sync run when a manual CSV is uploaded for a stock', function () {
    $admin = User::factory()->admin()->create();
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'MSFT']);

    $file = UploadedFile::fake()->createWithContent('msft.csv', "datetime,open,high,low,close,volume\n2026-06-18 09:30:00,10,12,9,11,1000");

    $this->actingAs($admin)
        ->post("/admin/stocks/{$stock->id}/historical-prices", [
            'file' => $file,
            'timeframe' => Timeframe::OneDay->value,
        ])
        ->assertRedirect();

    $run = SyncRun::query()->where('type', ImportSyncLogger::TYPE_MANUAL_IMPORT)->latest('id')->firstOrFail();

    expect($run->meta['symbol'])->toBe('MSFT')
        ->and($run->created_count)->toBe(1);
});

it('forces daily candles for long chart ranges', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    StockPrice::factory()->create([
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::OneDay,
        'source_kind' => StockPrice::SOURCE_CANDLE,
        'open' => 10,
        'high' => 12,
        'low' => 9,
        'close' => 11,
        'volume' => 1000,
        'price_at' => CarbonImmutable::now()->subDays(10),
    ]);

    $response = $this->actingAs($user)->getJson("/stocks/{$stock->symbol}/candles?range=1y&timeframe=5m");

    $response->assertOk();

    expect($response->json('timeframe'))->toBe(Timeframe::OneDay->value);
});
