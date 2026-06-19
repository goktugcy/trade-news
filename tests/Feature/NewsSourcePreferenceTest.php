<?php

declare(strict_types=1);

use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\User;
use App\Models\UserNewsSourcePreference;
use Inertia\Testing\AssertableInertia as Assert;

it('lists all active sources as enabled by default', function () {
    $user = User::factory()->create();
    NewsSource::factory()->create(['name' => 'Alpha Wire', 'is_active' => true]);
    NewsSource::factory()->create(['name' => 'Beta Wire', 'is_active' => true]);

    $this->actingAs($user)
        ->get('/news')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('sources', 2)
            ->where('sources.0.enabled', true)
            ->where('sources.1.enabled', true));
});

it('hides news from a source the user disabled, on the feed and dashboard', function () {
    $user = User::factory()->create();
    $kept = NewsSource::factory()->create(['is_active' => true]);
    $hidden = NewsSource::factory()->create(['is_active' => true]);

    NewsItem::factory()->for($kept, 'source')->create(['is_matched' => true, 'title' => 'Kept headline']);
    NewsItem::factory()->for($hidden, 'source')->create(['is_matched' => true, 'title' => 'Hidden headline']);

    $this->actingAs($user)
        ->patch("/news/sources/{$hidden->id}", ['enabled' => false])
        ->assertRedirect();

    expect(UserNewsSourcePreference::query()->where('user_id', $user->id)->where('news_source_id', $hidden->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->get('/news')
        ->assertInertia(fn (Assert $page) => $page
            ->has('news.data', 1)
            ->where('news.data.0.title', 'Kept headline'));

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->has('feed', 1)
            ->where('feed.0.title', 'Kept headline'));
});

it('restores news when the source is re-enabled', function () {
    $user = User::factory()->create();
    $source = NewsSource::factory()->create(['is_active' => true]);
    NewsItem::factory()->for($source, 'source')->create(['is_matched' => true]);

    $this->actingAs($user)->patch("/news/sources/{$source->id}", ['enabled' => false])->assertRedirect();
    $this->actingAs($user)->patch("/news/sources/{$source->id}", ['enabled' => true])->assertRedirect();

    expect(UserNewsSourcePreference::query()->where('news_source_id', $source->id)->count())->toBe(0);

    $this->actingAs($user)
        ->get('/news')
        ->assertInertia(fn (Assert $page) => $page->has('news.data', 1));
});
