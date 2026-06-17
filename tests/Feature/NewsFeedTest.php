<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Models\NewsItem;
use App\Models\Stock;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the all-news feed with matched items', function () {
    $user = User::factory()->create();
    NewsItem::factory()->count(3)->create(['is_matched' => true, 'market' => Market::NASDAQ]);

    $this->actingAs($user)
        ->get('/news')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('news/Index')
            ->where('scope', 'all')
            ->has('news.data', 3));
});

it('filters the feed by market', function () {
    $user = User::factory()->create();
    NewsItem::factory()->count(2)->create(['is_matched' => true, 'market' => Market::BIST]);
    NewsItem::factory()->count(3)->create(['is_matched' => true, 'market' => Market::NASDAQ]);

    $this->actingAs($user)
        ->get('/news?market=BIST')
        ->assertInertia(fn (Assert $page) => $page->has('news.data', 2));
});

it('excludes unmatched news from the feed', function () {
    $user = User::factory()->create();
    NewsItem::factory()->create(['is_matched' => false]);

    $this->actingAs($user)
        ->get('/news')
        ->assertInertia(fn (Assert $page) => $page->has('news.data', 0));
});

it('limits the watchlist feed to followed stocks', function () {
    $user = User::factory()->create();
    $followed = Stock::factory()->create();
    $ignored = Stock::factory()->create();

    $watchedNews = NewsItem::factory()->create(['is_matched' => true]);
    $watchedNews->stocks()->attach($followed->id, ['match_type' => 'symbol', 'matched_term' => $followed->symbol, 'confidence' => 1, 'created_at' => now()]);

    $otherNews = NewsItem::factory()->create(['is_matched' => true]);
    $otherNews->stocks()->attach($ignored->id, ['match_type' => 'symbol', 'matched_term' => $ignored->symbol, 'confidence' => 1, 'created_at' => now()]);

    $user->watchlist()->create(['stock_id' => $followed->id]);

    $this->actingAs($user)
        ->get('/news/watchlist')
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'watchlist')
            ->has('news.data', 1));
});
