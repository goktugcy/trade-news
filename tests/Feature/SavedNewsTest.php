<?php

declare(strict_types=1);

use App\Models\NewsItem;
use App\Models\SavedNewsItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('saves a news item and lists it on the saved page', function () {
    $user = User::factory()->create();
    $news = NewsItem::factory()->create(['is_matched' => true]);

    $this->actingAs($user)->post("/news/{$news->id}/save")->assertRedirect();

    expect(SavedNewsItem::query()->where('user_id', $user->id)->where('news_item_id', $news->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->get('/news/saved')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('news/Saved')
            ->where('scope', 'saved')
            ->has('news.data', 1)
            ->where('news.data.0.id', $news->id)
            ->where('news.data.0.is_saved', true));
});

it('unsaves a news item', function () {
    $user = User::factory()->create();
    $news = NewsItem::factory()->create(['is_matched' => true]);

    $this->actingAs($user)->post("/news/{$news->id}/save")->assertRedirect();
    $this->actingAs($user)->delete("/news/{$news->id}/save")->assertRedirect();

    expect(SavedNewsItem::query()->where('news_item_id', $news->id)->count())->toBe(0);

    $this->actingAs($user)
        ->get('/news/saved')
        ->assertInertia(fn (Assert $page) => $page->has('news.data', 0));
});

it('keeps the saved feed scoped per user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $news = NewsItem::factory()->create(['is_matched' => true]);

    $this->actingAs($owner)->post("/news/{$news->id}/save")->assertRedirect();

    $this->actingAs($other)
        ->get('/news/saved')
        ->assertInertia(fn (Assert $page) => $page->has('news.data', 0));
});
