<?php

declare(strict_types=1);

use App\Models\Stock;
use App\Models\StockAlias;
use App\Services\News\StockAliasService;

function aliasService(): StockAliasService
{
    return app(StockAliasService::class);
}

it('builds a normalized alias index automatically when a stock is created', function () {
    $stock = Stock::factory()->nasdaq()->create([
        'symbol' => 'AAPL',
        'name' => 'Apple Inc.',
        'aliases' => ['Apple'],
    ]);

    $rows = StockAlias::query()->where('stock_id', $stock->id)->get()->keyBy('normalized');

    expect($rows->get('aapl')?->kind)->toBe(StockAlias::KIND_TICKER)
        ->and((float) $rows->get('aapl')->confidence)->toBe(1.0)
        ->and($rows->get('apple inc')?->kind)->toBe(StockAlias::KIND_NAME)
        ->and((float) $rows->get('apple inc')->confidence)->toBe(0.95)
        ->and($rows->get('apple')?->kind)->toBe(StockAlias::KIND_ALIAS)
        ->and((float) $rows->get('apple')->confidence)->toBe(0.9);
});

it('matches by ticker, name and alias with the spec confidence scale', function () {
    Stock::factory()->nasdaq()->create(['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'aliases' => ['Apple']]);

    $ticker = aliasService()->relatedStocks('AAPL jumps on earnings beat');
    $name = aliasService()->relatedStocks('Apple Inc. reported quarterly earnings');
    $alias = aliasService()->relatedStocks('Apple unveils a new product line');

    expect(reset($ticker)['match_type'])->toBe('symbol')
        ->and(reset($ticker)['confidence'])->toBe(1.0)
        ->and(reset($name)['match_type'])->toBe('name')
        ->and(reset($name)['confidence'])->toBe(0.95)
        ->and(reset($alias)['match_type'])->toBe('alias')
        ->and(reset($alias)['confidence'])->toBe(0.9);
});

it('keeps only the highest-confidence reason per stock', function () {
    Stock::factory()->nasdaq()->create(['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'aliases' => ['Apple']]);

    // Text contains both the ticker and the alias → ticker (1.0) wins.
    $matches = aliasService()->relatedStocks('AAPL: Apple shares climb');

    expect($matches)->toHaveCount(1)
        ->and(reset($matches)['confidence'])->toBe(1.0)
        ->and(reset($matches)['match_type'])->toBe('symbol');
});

it('links curated brand aliases such as Facebook to META', function () {
    Stock::factory()->nasdaq()->create(['symbol' => 'META', 'name' => 'Meta Platforms Inc.', 'aliases' => []]);

    $matches = aliasService()->relatedStocks('Facebook rolls out a new feature');

    expect($matches)->toHaveCount(1)
        ->and(reset($matches)['match_type'])->toBe('alias');
});

it('links expanded curated aliases (Instagram → META, AWS/Amazon → AMZN)', function () {
    Stock::factory()->nasdaq()->create(['symbol' => 'META', 'name' => 'Meta Platforms Inc.', 'aliases' => []]);
    Stock::factory()->nasdaq()->create(['symbol' => 'AMZN', 'name' => 'Amazon.com, Inc.', 'aliases' => []]);

    $instagram = aliasService()->relatedStocks('Instagram launches a new feature');
    $amazon = aliasService()->relatedStocks('Amazon reports record holiday sales');
    $aws = aliasService()->relatedStocks('Outage hits AWS cloud customers');

    expect($instagram)->toHaveCount(1)
        ->and(reset($instagram)['match_type'])->toBe('alias')
        ->and($amazon)->toHaveCount(1)
        ->and($aws)->toHaveCount(1);

    // The short curated alias is case-sensitive — lowercase "aws" must not match.
    expect(aliasService()->relatedStocks('he saws wood all day'))->toBe([]);
});

it('does not match aliases inside unrelated longer words', function () {
    Stock::factory()->nasdaq()->create(['symbol' => 'META', 'name' => 'Meta Platforms Inc.', 'aliases' => ['Meta']]);

    expect(aliasService()->relatedStocks('The metaverse trend grows'))->toBe([]);
});

it('rebuilds the alias index when a stock is renamed', function () {
    $stock = Stock::factory()->nasdaq()->create(['symbol' => 'XCO', 'name' => 'Old Name', 'aliases' => []]);

    expect(StockAlias::query()->where('stock_id', $stock->id)->where('normalized', 'old name')->exists())->toBeTrue();

    $stock->update(['name' => 'New Name']);

    expect(StockAlias::query()->where('stock_id', $stock->id)->where('normalized', 'old name')->exists())->toBeFalse()
        ->and(StockAlias::query()->where('stock_id', $stock->id)->where('normalized', 'new name')->exists())->toBeTrue();
});

it('ignores inactive stocks when matching', function () {
    Stock::factory()->nasdaq()->create(['symbol' => 'DEAD', 'name' => 'Defunct Corp', 'aliases' => [], 'is_active' => false]);

    expect(aliasService()->relatedStocks('DEAD reports results'))->toBe([]);
});
