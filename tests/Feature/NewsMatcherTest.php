<?php

declare(strict_types=1);

use App\Enums\Market;
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
