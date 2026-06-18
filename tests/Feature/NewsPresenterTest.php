<?php

declare(strict_types=1);

use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Support\Presenters\NewsPresenter;
use Carbon\CarbonImmutable;

it('keeps image URL and published timestamp in the news card payload', function () {
    $source = NewsSource::factory()->create(['name' => 'RSS Wire']);
    $publishedAt = CarbonImmutable::parse('2026-06-18 12:30:00', 'UTC');
    $news = NewsItem::factory()->for($source, 'source')->create([
        'image_url' => 'https://cdn.test/story.jpg',
        'published_at' => $publishedAt,
    ]);

    $payload = NewsPresenter::card($news->load('source'));

    expect($payload['image_url'])->toBe('https://cdn.test/story.jpg')
        ->and($payload['published_at'])->toBe($publishedAt->toIso8601String())
        ->and($payload['source'])->toBe('RSS Wire')
        ->and($payload)->toHaveKeys(['image_url', 'published_at', 'published_for_humans']);
});
