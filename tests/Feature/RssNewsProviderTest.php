<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Models\NewsSource;
use App\Services\News\RssNewsProvider;
use Database\Seeders\ApiProviderSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function rssXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Reuters Business</title>
    <item>
      <title>Apple beats Q3 earnings estimates</title>
      <link>https://reuters.test/apple-q3</link>
      <description>&lt;p&gt;Apple reported strong results.&lt;/p&gt;</description>
      <pubDate>Wed, 17 Jun 2026 14:30:00 GMT</pubDate>
    </item>
    <item>
      <title>Fed holds interest rates steady</title>
      <link>https://reuters.test/fed-rates</link>
      <description>The central bank kept rates unchanged.</description>
      <pubDate>Wed, 17 Jun 2026 12:00:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML;
}

function rssContentOnlyXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <title>Investing.com</title>
    <item>
      <title>Nvidia expands AI chip production</title>
      <link>https://investing.test/nvidia-ai</link>
      <content:encoded><![CDATA[<p>Nvidia said it will expand AI chip production next quarter.</p>]]></content:encoded>
      <pubDate>Wed, 17 Jun 2026 14:30:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML;
}

function rssWithoutDetailsXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Minimal Feed</title>
    <item>
      <title>Market update headline only</title>
      <link>https://feed.test/headline-only</link>
    </item>
  </channel>
</rss>
XML;
}

function rssMediaImageXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Media Feed</title>
    <item>
      <title>Meta announces data center expansion</title>
      <link>https://feed.test/meta-data-center</link>
      <media:thumbnail url="https://cdn.test/meta-thumb.jpg" />
      <pubDate>Wed, 17 Jun 2026 14:30:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML;
}

function rssEmbeddedImageXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <title>Content Feed</title>
    <item>
      <title>Microsoft unveils cloud update</title>
      <link>https://feed.test/microsoft-cloud</link>
      <description><![CDATA[<p>Cloud update</p><img src="https://cdn.test/msft.jpg" alt="">]]></description>
      <content:encoded><![CDATA[<p>Cloud update detail.</p>]]></content:encoded>
    </item>
  </channel>
</rss>
XML;
}

function rssInvalidImageXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Invalid Image Feed</title>
    <item>
      <title>Invalid image headline</title>
      <link>https://feed.test/invalid-image</link>
      <media:thumbnail url="data:image/png;base64,abc" />
      <description><![CDATA[<p>Story</p><img src="/relative.jpg" alt="">]]></description>
    </item>
  </channel>
</rss>
XML;
}

it('parses an RSS feed into normalized NewsItemData', function () {
    Http::fake(['https://feed.test/rss' => Http::response(rssXml(), 200)]);

    $provider = new RssNewsProvider([
        ['key' => 'reuters', 'name' => 'Reuters', 'market' => 'NASDAQ', 'url' => 'https://feed.test/rss'],
    ]);

    $items = $provider->fetchLatest(Market::NASDAQ);

    expect($items)->toHaveCount(2)
        ->and($items[0]->title)->toBe('Apple beats Q3 earnings estimates')
        ->and($items[0]->url)->toBe('https://reuters.test/apple-q3')
        ->and($items[0]->summary)->toContain('Apple reported strong results')
        ->and($items[0]->market)->toBe(Market::NASDAQ)
        ->and($items[0]->sourceKey)->toBe('reuters')
        ->and($items[0]->publishedAt?->toDateString())->toBe('2026-06-17');
});

it('skips a failing feed without throwing', function () {
    Http::fake(['https://broken.test/rss' => Http::response('', 500)]);

    $provider = new RssNewsProvider([
        ['key' => 'broken', 'name' => 'Broken', 'market' => null, 'url' => 'https://broken.test/rss'],
    ]);

    expect($provider->fetchLatest())->toBe([]);
});

it('uses cleaned content as the summary when RSS description is missing', function () {
    Http::fake(['https://feed.test/content-only' => Http::response(rssContentOnlyXml(), 200)]);

    $provider = new RssNewsProvider([
        ['key' => 'investing', 'name' => 'Investing.com', 'market' => 'NASDAQ', 'url' => 'https://feed.test/content-only'],
    ]);

    $items = $provider->fetchLatest(Market::NASDAQ);

    expect($items)->toHaveCount(1)
        ->and($items[0]->summary)->toBe('Nvidia said it will expand AI chip production next quarter.');
});

it('leaves the summary empty when RSS provides neither description nor content', function () {
    Http::fake(['https://feed.test/headline-only' => Http::response(rssWithoutDetailsXml(), 200)]);

    $provider = new RssNewsProvider([
        ['key' => 'minimal', 'name' => 'Minimal Feed', 'market' => null, 'url' => 'https://feed.test/headline-only'],
    ]);

    $items = $provider->fetchLatest();

    expect($items)->toHaveCount(1)
        ->and($items[0]->summary)->toBeNull();
});

it('extracts image URLs from RSS media and embedded content fields', function (string $xml, string $expectedImageUrl) {
    Http::fake(['https://feed.test/images' => Http::response($xml, 200)]);

    $provider = new RssNewsProvider([
        ['key' => 'images', 'name' => 'Image Feed', 'market' => null, 'url' => 'https://feed.test/images'],
    ]);

    $items = $provider->fetchLatest();

    expect($items)->toHaveCount(1)
        ->and($items[0]->imageUrl)->toBe($expectedImageUrl);
})->with([
    'media thumbnail' => [rssMediaImageXml(), 'https://cdn.test/meta-thumb.jpg'],
    'embedded html image' => [rssEmbeddedImageXml(), 'https://cdn.test/msft.jpg'],
]);

it('rejects invalid or non-http RSS image URLs', function () {
    Http::fake(['https://feed.test/invalid-images' => Http::response(rssInvalidImageXml(), 200)]);

    $provider = new RssNewsProvider([
        ['key' => 'invalid-images', 'name' => 'Invalid Image Feed', 'market' => null, 'url' => 'https://feed.test/invalid-images'],
    ]);

    $items = $provider->fetchLatest();

    expect($items)->toHaveCount(1)
        ->and($items[0]->imageUrl)->toBeNull();
});

it('reads active RSS feeds from the database and skips inactive sources', function () {
    Http::fake([
        'https://active.test/rss' => Http::response(rssXml(), 200),
        'https://inactive.test/rss' => Http::response(rssXml(), 200),
    ]);

    NewsSource::factory()->create([
        'key' => 'active-rss',
        'name' => 'Active RSS',
        'provider' => 'rss',
        'market' => 'NASDAQ',
        'feed_url' => 'https://active.test/rss',
        'is_active' => true,
    ]);
    NewsSource::factory()->create([
        'key' => 'inactive-rss',
        'name' => 'Inactive RSS',
        'provider' => 'rss',
        'market' => 'NASDAQ',
        'feed_url' => 'https://inactive.test/rss',
        'is_active' => false,
    ]);

    $items = (new RssNewsProvider)->fetchLatest(Market::NASDAQ);

    expect($items)->toHaveCount(2)
        ->and($items[0]->sourceKey)->toBe('active-rss');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://active.test/rss');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://inactive.test/rss');
});

it('seeds configured RSS feed URLs into news sources', function () {
    NewsSource::factory()->create([
        'key' => 'seeded-rss',
        'provider' => 'rss',
        'is_active' => false,
    ]);

    config()->set('tradenews.news.providers.rss.feeds', [[
        'key' => 'seeded-rss',
        'name' => 'Seeded RSS',
        'market' => 'NASDAQ',
        'url' => 'https://seeded.test/rss',
        'homepage_url' => 'https://seeded.test',
    ]]);

    $this->seed(ApiProviderSeeder::class);

    $source = NewsSource::query()->where('key', 'seeded-rss')->firstOrFail();

    expect($source->feed_url)->toBe('https://seeded.test/rss')
        ->and($source->homepage_url)->toBe('https://seeded.test')
        ->and($source->provider)->toBe('rss')
        ->and($source->is_active)->toBeFalse();
});
