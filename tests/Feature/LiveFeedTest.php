<?php

declare(strict_types=1);

use App\Models\NewsItem;
use App\Models\Stock;
use App\Models\User;

it('returns only news newer than the after cursor for the live feed', function () {
    $user = User::factory()->create();
    $older = NewsItem::factory()->create(['is_matched' => true]);
    $newer = NewsItem::factory()->create(['is_matched' => true]);

    $response = $this->actingAs($user)
        ->getJson('/news/live?scope=all&after='.$older->id)
        ->assertOk();

    $ids = collect($response->json('items'))->pluck('id');

    expect($ids)->toContain($newer->id)
        ->and($ids)->not->toContain($older->id)
        ->and($response->json('latest_id'))->toBe($newer->id);
});

it('returns in-place updates for the provided visible ids', function () {
    $user = User::factory()->create();
    $item = NewsItem::factory()->create(['is_matched' => true]);

    $response = $this->actingAs($user)
        ->getJson("/news/live?scope=all&after={$item->id}&ids={$item->id}")
        ->assertOk();

    expect(collect($response->json('updates'))->pluck('id'))->toContain($item->id)
        ->and($response->json('updates.0.translation_status'))->toBe('original');
});

it('scopes the live feed to the watchlist', function () {
    $user = User::factory()->create();
    $followed = Stock::factory()->create();
    $ignored = Stock::factory()->create();

    $watched = NewsItem::factory()->create(['is_matched' => true]);
    $watched->stocks()->attach($followed->id, ['match_type' => 'symbol', 'matched_term' => $followed->symbol, 'confidence' => 1, 'created_at' => now()]);

    $other = NewsItem::factory()->create(['is_matched' => true]);
    $other->stocks()->attach($ignored->id, ['match_type' => 'symbol', 'matched_term' => $ignored->symbol, 'confidence' => 1, 'created_at' => now()]);

    $user->watchlist()->create(['stock_id' => $followed->id]);

    $response = $this->actingAs($user)
        ->getJson('/news/live?scope=watchlist&after=0&ids='.$watched->id.','.$other->id)
        ->assertOk();

    $updateIds = collect($response->json('updates'))->pluck('id');

    expect($updateIds)->toContain($watched->id)
        ->and($updateIds)->not->toContain($other->id);
});

it('filters the live feed by stock symbol', function () {
    $user = User::factory()->create();
    $apple = Stock::factory()->create(['symbol' => 'AAPL']);

    $matched = NewsItem::factory()->create(['is_matched' => true]);
    $matched->stocks()->attach($apple->id, ['match_type' => 'symbol', 'matched_term' => 'AAPL', 'confidence' => 1, 'created_at' => now()]);

    $unmatched = NewsItem::factory()->create(['is_matched' => true]);

    $response = $this->actingAs($user)
        ->getJson('/news/live?scope=all&stock=AAPL&ids='.$matched->id.','.$unmatched->id)
        ->assertOk();

    $updateIds = collect($response->json('updates'))->pluck('id');

    expect($updateIds)->toContain($matched->id)
        ->and($updateIds)->not->toContain($unmatched->id);
});

it('returns live quotes plus ticker and market status', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'MSFT']);

    $response = $this->actingAs($user)
        ->getJson('/stocks/live?symbols=MSFT')
        ->assertOk()
        ->assertJsonStructure([
            'quotes' => [['symbol', 'price', 'change', 'change_percent', 'quote_at']],
            'ticker',
            'top_movers' => ['gainers', 'losers'],
            'market_status',
        ]);

    expect(collect($response->json('quotes'))->pluck('symbol'))->toContain('MSFT');
});
