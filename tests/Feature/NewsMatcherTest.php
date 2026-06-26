<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Jobs\MatchNewsWithStocksJob;
use App\Models\NewsItem;
use App\Models\Stock;
use App\Services\News\NewsMatcherService;

beforeEach(function () {
    Stock::factory()->nasdaq()->create([
        'symbol' => 'AAPL',
        'name' => 'Apple Inc.',
        'aliases' => ['AAPL', 'Apple', 'Apple Inc.'],
    ]);

    Stock::factory()->nasdaq()->create([
        'symbol' => 'MSFT',
        'name' => 'Microsoft Corporation',
        'aliases' => ['MSFT', 'Microsoft', 'Microsoft Corporation'],
    ]);

    Stock::factory()->nasdaq()->create([
        'symbol' => 'UAL',
        'name' => 'United Airlines Holdings',
        'aliases' => ['UAL', 'United Airlines'],
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
    $news = makeNews('AAPL announces a new device');

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->is_matched)->toBeTrue()
        ->and($news->stocks()->pluck('symbol')->all())->toContain('AAPL');
});

it('matches a news item by company name and alias', function () {
    $news = makeNews('Apple reports record device sales');

    (new NewsMatcherService)->match($news);

    expect($news->stocks()->pluck('symbol')->all())->toContain('AAPL');
});

it('matches multiple stocks in one headline', function () {
    $news = makeNews('Microsoft and Apple announce a new cloud partnership');

    (new NewsMatcherService)->match($news);

    expect($news->stocks()->pluck('symbol')->sort()->values()->all())->toEqual(['AAPL', 'MSFT']);
});

it('does not match unrelated news', function () {
    $news = makeNews('Local bakery wins regional dessert award');

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->is_matched)->toBeTrue()
        ->and($news->stocks()->count())->toBe(0);
});

it('does not match company aliases inside unrelated longer words', function () {
    Stock::factory()->nasdaq()->create([
        'symbol' => 'META',
        'name' => 'Meta Platforms Inc.',
        'aliases' => ['Meta'],
    ]);

    $news = makeNews('The metaverse trend is changing online gaming communities');

    (new NewsMatcherService)->match($news);

    expect($news->stocks()->pluck('symbol')->all())->not->toContain('META');
});

it('requires short aliases to appear as exact cased standalone tokens', function () {
    $lowercaseNews = makeNews('ual customer experience report compares global airlines');
    $uppercaseNews = makeNews('UAL reports record passenger numbers');
    $matcher = new NewsMatcherService;

    $matcher->match($lowercaseNews);
    $matcher->flushIndex();
    $matcher->match($uppercaseNews);

    expect($lowercaseNews->stocks()->pluck('symbol')->all())->not->toContain('UAL')
        ->and($uppercaseNews->stocks()->pluck('symbol')->all())->toContain('UAL');
});

it('does not match ticker symbols inside English contractions or possessives', function (string $headline) {
    Stock::factory()->nasdaq()->create([
        'symbol' => 'HERE',
        'name' => 'HERE Group Ltd',
        'aliases' => ['HERE', 'HERE Group Ltd'],
    ]);

    $news = makeNews($headline);

    (new NewsMatcherService)->match($news);

    expect($news->stocks()->pluck('symbol')->all())->not->toContain('HERE');
})->with([
    'normal contraction' => ["Here's why markets are cautious before the Fed decision"],
    'uppercase possessive' => ["HERE's what investors should watch before earnings"],
]);

it('still matches uppercase standalone ticker symbols', function (string $headline) {
    Stock::factory()->nasdaq()->create([
        'symbol' => 'HERE',
        'name' => 'HERE Group Ltd',
        'aliases' => ['HERE', 'HERE Group Ltd'],
    ]);

    $news = makeNews($headline);

    (new NewsMatcherService)->match($news);

    expect($news->stocks()->pluck('symbol')->all())->toContain('HERE');
})->with([
    'plain ticker' => ['HERE reports stronger revenue guidance'],
    'cashtag ticker' => ['$HERE reports stronger revenue guidance'],
]);

it('records the match type and is idempotent', function () {
    $news = makeNews('MSFT shares climb on strong cloud demand');
    $matcher = new NewsMatcherService;

    $matcher->match($news);
    $matcher->flushIndex();
    $matcher->match($news); // second run should not duplicate

    expect($news->matches()->count())->toBe(1)
        ->and($news->matches()->first()->match_type)->toBe('symbol');
});

it('sets the market from the matched stock when the source market is missing', function () {
    $news = NewsItem::factory()->create([
        'title' => 'Apple shares rise after new iPhone demand report',
        'summary' => null,
        'content' => null,
        'market' => null,
        'is_matched' => false,
    ]);

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->market)->toBe(Market::NASDAQ);
});

it('keeps a NASDAQ item as NASDAQ when NASDAQ stocks match', function () {
    $news = NewsItem::factory()->create([
        'title' => 'Apple reports record device sales',
        'summary' => null,
        'content' => null,
        'market' => Market::NASDAQ,
        'is_matched' => false,
    ]);

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->market)->toBe(Market::NASDAQ);
});

it('keeps the source market when multiple stocks from the same market match', function () {
    $news = NewsItem::factory()->create([
        'title' => 'Apple and Microsoft announce a new technology partnership',
        'summary' => null,
        'content' => null,
        'market' => Market::NASDAQ,
        'is_matched' => false,
    ]);

    (new NewsMatcherService)->match($news);

    expect($news->fresh()->market)->toBe(Market::NASDAQ);
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
        'market' => null,
        'is_matched' => true,
    ]);

    (new MatchNewsWithStocksJob(repairMarkets: true))->handle(new NewsMatcherService);

    expect($news->fresh()->market)->toBe(Market::NASDAQ);
});

it('can repair market labels synchronously from the match command', function () {
    $news = NewsItem::factory()->create([
        'title' => 'Microsoft announces new cloud infrastructure investment',
        'summary' => null,
        'content' => null,
        'market' => null,
        'is_matched' => true,
    ]);

    $this->artisan('tradenews:match-news --repair-markets --sync')->assertSuccessful();

    expect($news->fresh()->market)->toBe(Market::NASDAQ);
});
