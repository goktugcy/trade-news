<?php

declare(strict_types=1);

use App\Models\NewsSource;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('lets an admin create and update RSS news sources', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/news-sources', [
        'key' => 'custom-rss',
        'name' => 'Custom RSS',
        'feed_url' => 'https://feeds.test/rss.xml',
        'homepage_url' => 'https://feeds.test',
        'market' => null,
        'language' => 'tr',
        'is_active' => true,
    ])->assertRedirect();

    $source = NewsSource::query()->where('key', 'custom-rss')->firstOrFail();

    expect($source->provider)->toBe('rss')
        ->and($source->feed_url)->toBe('https://feeds.test/rss.xml')
        ->and($source->market)->toBeNull()
        ->and($source->language)->toBe('tr')
        ->and($source->is_active)->toBeTrue();

    $this->actingAs($admin)->put("/admin/news-sources/{$source->id}", [
        'key' => 'custom-rss',
        'name' => 'Custom RSS Updated',
        'feed_url' => 'https://feeds.test/updated.xml',
        'homepage_url' => null,
        'market' => 'NASDAQ',
        'language' => 'en',
        'is_active' => false,
    ])->assertRedirect();

    $source->refresh();

    expect($source->name)->toBe('Custom RSS Updated')
        ->and($source->feed_url)->toBe('https://feeds.test/updated.xml')
        ->and($source->homepage_url)->toBeNull()
        ->and($source->market)->toBe('NASDAQ')
        ->and($source->language)->toBe('en')
        ->and($source->is_active)->toBeFalse();
});

it('validates RSS news source create and update payloads', function () {
    $admin = User::factory()->admin()->create();
    $source = NewsSource::factory()->create([
        'provider' => 'rss',
        'key' => 'existing-rss',
        'feed_url' => 'https://existing.test/rss.xml',
    ]);

    $this->actingAs($admin)->post('/admin/news-sources', [
        'key' => 'existing-rss',
        'name' => '',
        'feed_url' => 'not-a-url',
        'homepage_url' => 'not-a-url',
        'market' => 'INVALID',
        'is_active' => true,
    ])->assertSessionHasErrors(['key', 'name', 'feed_url', 'homepage_url', 'market']);

    $this->actingAs($admin)->put("/admin/news-sources/{$source->id}", [
        'key' => 'bad key',
        'name' => 'Bad key',
        'feed_url' => 'https://valid.test/rss.xml',
        'homepage_url' => null,
        'market' => null,
        'is_active' => true,
    ])->assertSessionHasErrors(['key']);
});

it('deactivates RSS sources instead of deleting them', function () {
    $admin = User::factory()->admin()->create();
    $source = NewsSource::factory()->create([
        'provider' => 'rss',
        'is_active' => true,
        'feed_url' => 'https://feeds.test/rss.xml',
    ]);

    $this->actingAs($admin)
        ->delete("/admin/news-sources/{$source->id}")
        ->assertRedirect();

    expect($source->fresh())->not->toBeNull()
        ->and($source->fresh()?->is_active)->toBeFalse();
});

it('falls back to configured RSS URLs in the admin edit payload', function () {
    $admin = User::factory()->admin()->create();
    NewsSource::factory()->create([
        'provider' => 'rss',
        'key' => 'legacy-rss',
        'feed_url' => null,
        'homepage_url' => null,
    ]);

    config([
        'tradenews.news.providers.rss.feeds' => [[
            'key' => 'legacy-rss',
            'name' => 'Legacy RSS',
            'market' => null,
            'url' => 'https://configured.test/rss.xml',
            'homepage_url' => 'https://configured.test',
        ]],
    ]);

    $this->actingAs($admin)
        ->get('/admin/news-sources')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/NewsSources')
            ->where('sources.0.feed_url', 'https://configured.test/rss.xml')
            ->where('sources.0.homepage_url', 'https://configured.test'));
});

it('keeps non-RSS sources limited to the existing toggle behavior', function () {
    $admin = User::factory()->admin()->create();
    $source = NewsSource::factory()->create([
        'provider' => 'finnhub-news',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->patch("/admin/news-sources/{$source->id}/toggle")
        ->assertRedirect();

    expect($source->fresh()?->is_active)->toBeFalse();

    $this->actingAs($admin)->put("/admin/news-sources/{$source->id}", [
        'key' => $source->key,
        'name' => $source->name,
        'feed_url' => 'https://feeds.test/rss.xml',
        'homepage_url' => null,
        'market' => null,
        'is_active' => true,
    ])->assertForbidden();
});
