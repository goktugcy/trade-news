<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Timeframe;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Inertia\Support\SessionKey;

function uploadFile(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('imports a manual CSV for one stock as candle data', function () {
    $admin = User::factory()->admin()->create();
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'AAPL']);

    $file = uploadFile('aapl.csv', implode("\n", [
        'datetime,open,high,low,close,volume',
        '2026-06-18 09:30:00,10,12,9,11,1000',
    ]));

    $this->actingAs($admin)
        ->post("/admin/stocks/{$stock->id}/historical-prices", [
            'file' => $file,
            'timeframe' => Timeframe::FiveMinutes->value,
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.imported', 1);

    $price = StockPrice::query()->where('stock_id', $stock->id)->firstOrFail();

    expect($price->timeframe)->toBe(Timeframe::FiveMinutes)
        ->and($price->provider_key)->toBe(StockPrice::PROVIDER_MANUAL_CSV)
        ->and($price->source_kind)->toBe(StockPrice::SOURCE_CANDLE)
        ->and($price->open)->toBe(10.0)
        ->and($price->high)->toBe(12.0)
        ->and($price->low)->toBe(9.0)
        ->and($price->close)->toBe(11.0)
        ->and($price->volume)->toBe(1000.0)
        ->and($price->price_at->toDateTimeString())->toBe('2026-06-18 09:30:00')
        ->and($price->created_at?->toDateTimeString())->toBe('2026-06-18 09:30:00');
});

it('updates duplicate manual candles instead of creating duplicates', function () {
    $admin = User::factory()->admin()->create();
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'MSFT']);

    StockPrice::factory()->create([
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::FiveMinutes,
        'provider_key' => 'finnhub',
        'source_kind' => StockPrice::SOURCE_CANDLE,
        'open' => 8,
        'high' => 9,
        'low' => 7,
        'close' => 8.5,
        'volume' => 10,
        'price_at' => '2026-06-18 09:30:00',
        'created_at' => '2026-06-18 09:30:00',
    ]);

    $file = uploadFile('msft.csv', implode("\n", [
        'datetime,open,high,low,close,volume',
        '2026-06-18 09:30:00,10,12,9,11,1000',
    ]));

    $this->actingAs($admin)
        ->post("/admin/stocks/{$stock->id}/historical-prices", [
            'file' => $file,
            'timeframe' => Timeframe::FiveMinutes->value,
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.updated', 1);

    expect(StockPrice::query()->where('stock_id', $stock->id)->count())->toBe(1);

    $price = StockPrice::query()->where('stock_id', $stock->id)->firstOrFail();

    expect($price->provider_key)->toBe(StockPrice::PROVIDER_MANUAL_CSV)
        ->and($price->close)->toBe(11.0)
        ->and($price->volume)->toBe(1000.0);
});

it('creates missing stocks and imports Stooq daily rows by ticker suffix', function () {
    $admin = User::factory()->admin()->create();

    $file = uploadFile('stooq.txt', implode("\n", [
        '<TICKER>,<PER>,<DATE>,<TIME>,<OPEN>,<HIGH>,<LOW>,<CLOSE>,<VOL>,<OPENINT>',
        'ALEC.US,D,20190207,000000,18.7,19.5,17.51,18,3626074,0',
    ]));

    $this->actingAs($admin)
        ->post('/admin/stocks/historical-prices/stooq', [
            'file' => $file,
            'fallback_market' => 'ALL',
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.stocks_created', 1);

    $stock = Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'ALEC')->firstOrFail();
    $price = StockPrice::query()->where('stock_id', $stock->id)->firstOrFail();

    expect($stock->name)->toBe('ALEC')
        ->and($price->timeframe)->toBe(Timeframe::OneDay)
        ->and($price->provider_key)->toBe(StockPrice::PROVIDER_STOOQ_UPLOAD)
        ->and($price->close)->toBe(18.0)
        ->and($price->price_at->toDateTimeString())->toBe('2019-02-07 00:00:00');
});

it('imports generic bulk CSV rows for multiple stocks', function () {
    $admin = User::factory()->admin()->create();
    Stock::factory()->nasdaq()->create(['symbol' => 'AAPL', 'name' => 'Apple Inc.']);

    $file = uploadFile('bulk.csv', implode("\n", [
        'symbol,market,timeframe,datetime,open,high,low,close,volume,name',
        'AAPL,NASDAQ,1d,2026-06-18,190,195,188,194,1200000,Apple Inc.',
        'NVDA,NASDAQ,1d,2026-06-18,170,175,168,174,135821755,NVIDIA',
        'MSFT.US,,5m,2026-06-18 09:30:00,420,425,419,424,500000,Microsoft',
    ]));

    $this->actingAs($admin)
        ->post('/admin/stocks/historical-prices/stooq', [
            'file' => $file,
            'fallback_market' => 'ALL',
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.imported', 3)
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.stocks_created', 2);

    $nvda = Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'NVDA')->firstOrFail();
    $msft = Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'MSFT')->firstOrFail();

    expect(StockPrice::query()->where('provider_key', StockPrice::PROVIDER_BULK_CSV)->count())->toBe(3)
        ->and(StockPrice::query()->where('stock_id', $nvda->id)->where('close', 174)->exists())->toBeTrue()
        ->and(StockPrice::query()->where('stock_id', $msft->id)->where('timeframe', Timeframe::FiveMinutes->value)->exists())->toBeTrue();
});

it('imports multiple bulk files in one request', function () {
    $admin = User::factory()->admin()->create();

    $generic = uploadFile('bulk-aapl.csv', implode("\n", [
        'symbol,market,timeframe,datetime,open,high,low,close,volume,name',
        'AAPL,NASDAQ,1d,2026-06-18,190,195,188,194,1200000,Apple Inc.',
    ]));
    $stooq = uploadFile('stooq-msft.txt', implode("\n", [
        '<TICKER>,<PER>,<DATE>,<TIME>,<OPEN>,<HIGH>,<LOW>,<CLOSE>,<VOL>,<OPENINT>',
        'MSFT.US,D,20260618,000000,420,425,419,424,500000,0',
    ]));

    $this->actingAs($admin)
        ->post('/admin/stocks/historical-prices/stooq', [
            'files' => [$generic, $stooq],
            'fallback_market' => 'ALL',
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.source', 'bulk-upload')
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.imported', 2)
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.stocks_created', 2);

    expect(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'AAPL')->exists())->toBeTrue()
        ->and(Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'MSFT')->exists())->toBeTrue()
        ->and(StockPrice::query()->where('provider_key', StockPrice::PROVIDER_BULK_CSV)->count())->toBe(1)
        ->and(StockPrice::query()->where('provider_key', StockPrice::PROVIDER_STOOQ_UPLOAD)->count())->toBe(1);
});

it('skips invalid files during multiple bulk import without blocking valid files', function () {
    $admin = User::factory()->admin()->create();

    $invalid = uploadFile('invalid.csv', "foo,bar\n1,2");
    $valid = uploadFile('valid.csv', implode("\n", [
        'symbol,market,timeframe,datetime,open,high,low,close,volume,name',
        'AAPL,NASDAQ,1d,2026-06-18,190,195,188,194,1200000,Apple Inc.',
    ]));

    $this->actingAs($admin)
        ->post('/admin/stocks/historical-prices/stooq', [
            'files' => [$invalid, $valid],
            'fallback_market' => 'ALL',
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.imported', 1)
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.skipped', 1);

    expect(StockPrice::query()->where('provider_key', StockPrice::PROVIDER_BULK_CSV)->count())->toBe(1)
        ->and(session(SessionKey::FLASH_DATA)['stock_import']['errors'][0])->toContain('invalid.csv');
});

it('skips generic bulk rows without market when all markets is selected', function () {
    $admin = User::factory()->admin()->create();

    $file = uploadFile('bulk-all.csv', implode("\n", [
        'symbol,market,timeframe,datetime,open,high,low,close,volume,name',
        'AAPL,NASDAQ,1d,2026-06-18,190,195,188,194,1200000,Apple Inc.',
        'UNKNOWN,,1d,2026-06-18,10,12,9,11,1000,Unknown Inc.',
    ]));

    $this->actingAs($admin)
        ->post('/admin/stocks/historical-prices/stooq', [
            'file' => $file,
            'fallback_market' => 'ALL',
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.imported', 1)
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.skipped', 1);

    expect(Stock::query()->where('symbol', 'AAPL')->where('market', Market::NASDAQ->value)->exists())->toBeTrue()
        ->and(Stock::query()->where('symbol', 'UNKNOWN')->exists())->toBeFalse();
});

it('imports thousands of generic bulk CSV rows in one request', function () {
    $admin = User::factory()->admin()->create();
    Stock::factory()->nasdaq()->create(['symbol' => 'BULK']);

    $lines = ['symbol,market,timeframe,datetime,open,high,low,close,volume'];

    $base = CarbonImmutable::parse('2026-06-18 09:30:00', Market::NASDAQ->timezone());

    for ($i = 0; $i < 2500; $i++) {
        $datetime = $base->addMinutes($i)->format('Y-m-d H:i:s');
        $open = 100 + ($i / 100);
        $close = $open + 0.5;
        $high = $close + 0.5;
        $low = $open - 0.5;

        $lines[] = "BULK,NASDAQ,1m,{$datetime},{$open},{$high},{$low},{$close},1000";
    }

    $this->actingAs($admin)
        ->post('/admin/stocks/historical-prices/stooq', [
            'file' => uploadFile('bulk-2500.csv', implode("\n", $lines)),
            'fallback_market' => Market::NASDAQ->value,
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.imported', 2500);

    expect(StockPrice::query()->where('provider_key', StockPrice::PROVIDER_BULK_CSV)->count())->toBe(2500);
});

it('maps Stooq intraday PER values to supported timeframes', function () {
    $admin = User::factory()->admin()->create();

    $file = uploadFile('intraday.txt', implode("\n", [
        '<TICKER>,<PER>,<DATE>,<TIME>,<OPEN>,<HIGH>,<LOW>,<CLOSE>,<VOL>,<OPENINT>',
        'TEST.US,5,20260618,093000,10,12,9,11,1000,0',
        'TEST.US,15,20260618,093000,11,13,10,12,1100,0',
        'TEST.US,60,20260618,100000,12,14,11,13,1200,0',
    ]));

    $this->actingAs($admin)
        ->post('/admin/stocks/historical-prices/stooq', [
            'file' => $file,
            'fallback_market' => Market::NASDAQ->value,
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.imported', 3);

    $stock = Stock::query()->where('market', Market::NASDAQ->value)->where('symbol', 'TEST')->firstOrFail();

    expect(StockPrice::query()->where('stock_id', $stock->id)->where('timeframe', Timeframe::FiveMinutes->value)->exists())->toBeTrue()
        ->and(StockPrice::query()->where('stock_id', $stock->id)->where('timeframe', Timeframe::FifteenMinutes->value)->exists())->toBeTrue()
        ->and(StockPrice::query()->where('stock_id', $stock->id)->where('timeframe', Timeframe::OneHour->value)->exists())->toBeTrue();
});

it('skips unsupported Stooq rows without writing candles', function () {
    $admin = User::factory()->admin()->create();

    $file = uploadFile('invalid-stooq.txt', implode("\n", [
        '<TICKER>,<PER>,<DATE>,<TIME>,<OPEN>,<HIGH>,<LOW>,<CLOSE>,<VOL>,<OPENINT>',
        'TEST.US,W,20260618,000000,10,12,9,11,1000,0',
        'TEST.L,D,20260618,000000,10,12,9,11,1000,0',
    ]));

    $this->actingAs($admin)
        ->post('/admin/stocks/historical-prices/stooq', [
            'file' => $file,
            'fallback_market' => Market::NASDAQ->value,
        ])
        ->assertRedirect()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.stock_import.skipped', 2);

    expect(Stock::query()->where('symbol', 'TEST')->exists())->toBeFalse()
        ->and(StockPrice::query()->count())->toBe(0);
});

it('validates required manual CSV columns', function () {
    $admin = User::factory()->admin()->create();
    $stock = Stock::factory()->nasdaq()->create();
    $file = uploadFile('missing-close.csv', "datetime,open,high,low\n2026-06-18,10,12,9");

    $this->actingAs($admin)
        ->post("/admin/stocks/{$stock->id}/historical-prices", [
            'file' => $file,
            'timeframe' => Timeframe::OneDay->value,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('file');
});

it('blocks non-admin users from historical imports', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $stock = Stock::factory()->nasdaq()->create();
    $file = uploadFile('aapl.csv', "datetime,open,high,low,close\n2026-06-18,10,12,9,11");

    $this->actingAs($user)
        ->post("/admin/stocks/{$stock->id}/historical-prices", [
            'file' => $file,
            'timeframe' => Timeframe::OneDay->value,
        ])
        ->assertForbidden();

    expect(StockPrice::query()->count())->toBe(0);
});
