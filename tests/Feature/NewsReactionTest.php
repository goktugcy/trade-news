<?php

declare(strict_types=1);

use App\Models\NewsItem;
use App\Models\NewsItemReaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('likes a news item and reflects the count on the feed', function () {
    $user = User::factory()->create();
    $news = NewsItem::factory()->create(['is_matched' => true]);

    $this->actingAs($user)->post("/news/{$news->id}/react", ['value' => 1])->assertRedirect();

    expect(NewsItemReaction::query()->where('user_id', $user->id)->where('news_item_id', $news->id)->value('value'))->toBe(1);

    $this->actingAs($user)
        ->get('/news')
        ->assertInertia(fn (Assert $page) => $page
            ->where('news.data.0.reaction', 1)
            ->where('news.data.0.like_count', 1)
            ->where('news.data.0.dislike_count', 0));
});

it('clears the reaction when the same value is sent again', function () {
    $user = User::factory()->create();
    $news = NewsItem::factory()->create(['is_matched' => true]);

    $this->actingAs($user)->post("/news/{$news->id}/react", ['value' => 1])->assertRedirect();
    $this->actingAs($user)->post("/news/{$news->id}/react", ['value' => 1])->assertRedirect();

    expect(NewsItemReaction::query()->where('news_item_id', $news->id)->count())->toBe(0);
});

it('flips a like to a dislike on the single row', function () {
    $user = User::factory()->create();
    $news = NewsItem::factory()->create(['is_matched' => true]);

    $this->actingAs($user)->post("/news/{$news->id}/react", ['value' => 1])->assertRedirect();
    $this->actingAs($user)->post("/news/{$news->id}/react", ['value' => -1])->assertRedirect();

    expect(NewsItemReaction::query()->where('news_item_id', $news->id)->count())->toBe(1)
        ->and(NewsItemReaction::query()->where('news_item_id', $news->id)->value('value'))->toBe(-1);
});

it('rejects invalid reaction values', function () {
    $user = User::factory()->create();
    $news = NewsItem::factory()->create(['is_matched' => true]);

    $this->actingAs($user)->post("/news/{$news->id}/react", ['value' => 5])->assertSessionHasErrors('value');
});
