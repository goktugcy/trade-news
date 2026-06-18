<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Jobs\MatchNewsWithStocksJob;
use App\Models\NewsItem;
use App\Models\Stock;
use App\Services\News\NewsMatcherService;

beforeEach(function () {
    Stock::factory()->create([
        'symbol' => 'THYAO',
        'name' => 'Türk Hava Yolları',
        'market' => Market::BIST,
        'aliases' => ['THYAO', 'Türk Hava Yolları', 'Turkish Airlines', 'THY'],
    ]);

    Stock::factory()->create([
        'symbol' => 'ASELS',
        'name' => 'Aselsan Elektronik',
        'market' => Market::BIST,
        'aliases' => ['ASELS', 'Aselsan', 'Aselsan Elektronik'],
    ]);
});

function makeNews(string $title): NewsItem
{
    return NewsItem::factory()->create([
        'title' => $title,
        'summary' => null,
        'content' => null,
        'is_matched' => false,
    ]);
}

it('matches a news item by ticker symbol', function () {
    $news = makeNews('THYAO announces new route to Tokyo');

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->is_matched)->toBeTrue()
        ->and($news->stocks()->pluck('symbol')->all())->toContain('THYAO');
});

it('matches a news item by company name and alias', function () {
    $news = makeNews('Turkish Airlines reports record passenger numbers');

    (new NewsMatcherService)->match($news);

    expect($news->stocks()->pluck('symbol')->all())->toContain('THYAO');
});

it('matches multiple stocks in one headline', function () {
    $news = makeNews('Aselsan and Turkish Airlines sign a new defense logistics deal');

    (new NewsMatcherService)->match($news);

    expect($news->stocks()->pluck('symbol')->sort()->values()->all())->toEqual(['ASELS', 'THYAO']);
});

it('does not match unrelated news', function () {
    $news = makeNews('Local bakery wins regional dessert award');

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->is_matched)->toBeTrue()
        ->and($news->stocks()->count())->toBe(0);
});

it('records the match type and is idempotent', function () {
    $news = makeNews('ASELS shares climb on strong export orders');
    $matcher = new NewsMatcherService;

    $matcher->match($news);
    $matcher->flushIndex();
    $matcher->match($news); // second run should not duplicate

    expect($news->matches()->count())->toBe(1)
        ->and($news->matches()->first()->match_type)->toBe('symbol');
});

it('moves a BIST-labelled item to NASDAQ when only NASDAQ stocks match', function () {
    Stock::factory()->nasdaq()->create([
        'symbol' => 'AAPL',
        'name' => 'Apple Inc.',
        'aliases' => ['Apple', 'AAPL'],
    ]);

    $news = NewsItem::factory()->create([
        'title' => 'Apple shares rise after new iPhone demand report',
        'summary' => null,
        'content' => null,
        'market' => Market::BIST,
        'is_matched' => false,
    ]);

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->market)->toBe(Market::NASDAQ);
});

it('keeps a BIST item as BIST when only BIST stocks match', function () {
    $news = NewsItem::factory()->create([
        'title' => 'Turkish Airlines reports record passenger numbers',
        'summary' => null,
        'content' => null,
        'market' => Market::BIST,
        'is_matched' => false,
    ]);

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->market)->toBe(Market::BIST);
});

it('keeps the source market when matched stocks span multiple markets', function () {
    Stock::factory()->nasdaq()->create([
        'symbol' => 'AAPL',
        'name' => 'Apple Inc.',
        'aliases' => ['Apple', 'AAPL'],
    ]);

    $news = NewsItem::factory()->create([
        'title' => 'Apple and Turkish Airlines announce a new travel technology partnership',
        'summary' => null,
        'content' => null,
        'market' => Market::BIST,
        'is_matched' => false,
    ]);

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->market)->toBe(Market::BIST);
});

it('can repair market labels for already matched news items', function () {
    Stock::factory()->nasdaq()->create([
        'symbol' => 'NVDA',
        'name' => 'Nvidia Corporation',
        'aliases' => ['Nvidia', 'NVDA'],
    ]);

    $news = NewsItem::factory()->create([
        'title' => 'Nvidia expands AI chip production',
        'summary' => null,
        'content' => null,
        'market' => Market::BIST,
        'is_matched' => true,
    ]);

    (new MatchNewsWithStocksJob(repairMarkets: true))->handle(new NewsMatcherService);

    expect($news->fresh()->market)->toBe(Market::NASDAQ);
});

it('can repair market labels synchronously from the match command', function () {
    Stock::factory()->nasdaq()->create([
        'symbol' => 'MSFT',
        'name' => 'Microsoft Corporation',
        'aliases' => ['Microsoft', 'MSFT'],
    ]);

    $news = NewsItem::factory()->create([
        'title' => 'Microsoft announces new cloud infrastructure investment',
        'summary' => null,
        'content' => null,
        'market' => Market::BIST,
        'is_matched' => true,
    ]);

    $this->artisan('tradenews:match-news --repair-markets --sync')->assertSuccessful();

    expect($news->fresh()->market)->toBe(Market::NASDAQ);
});
