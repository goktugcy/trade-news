<?php

declare(strict_types=1);

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;
use App\Models\NewsItem;
use App\Models\NewsItemSource;
use App\Services\News\NewsIngestor;
use App\Services\News\NewsProviderInterface;
use Carbon\CarbonImmutable;

/**
 * @param  array<int, NewsItemData>  $payloads
 */
function stubProvider(array $payloads): NewsProviderInterface
{
    return new class($payloads) implements NewsProviderInterface
    {
        public function __construct(private array $payloads) {}

        public function key(): string
        {
            return 'rss';
        }

        public function fetchLatest(?Market $market = null, int $limit = 50): array
        {
            return $this->payloads;
        }
    };
}

function item(
    string $title,
    string $sourceKey,
    string $sourceName,
    string $url,
    ?CarbonImmutable $at = null,
    ?string $summary = null,
): NewsItemData {
    return new NewsItemData(
        title: $title,
        summary: $summary,
        content: null,
        url: $url,
        imageUrl: null,
        publishedAt: $at ?? CarbonImmutable::parse('2026-06-17 14:00:00'),
        market: Market::NASDAQ,
        sourceKey: $sourceKey,
        sourceName: $sourceName,
    );
}

it('merges the same story from two sources into one article tracking both', function () {
    $provider = stubProvider([
        item('Apple beats Q3 earnings estimates', 'reuters', 'Reuters', 'https://reuters.test/a'),
        item('Q3 earnings estimates: Apple beats!', 'cnbc', 'CNBC', 'https://cnbc.test/a', CarbonImmutable::parse('2026-06-17 15:00:00')),
    ]);

    $created = (new NewsIngestor($provider))->ingest();

    expect(NewsItem::count())->toBe(1)
        ->and($created)->toHaveCount(1);

    $article = NewsItem::first();
    expect($article->source_count)->toBe(2)
        ->and($article->sources()->count())->toBe(2)
        // earliest publish time is kept
        ->and($article->published_at->format('H:i'))->toBe('14:00');
});

it('does not re-ingest the same article from the same source', function () {
    $payload = item('Fed holds rates steady', 'reuters', 'Reuters', 'https://reuters.test/fed');

    (new NewsIngestor(stubProvider([$payload])))->ingest();
    $second = (new NewsIngestor(stubProvider([$payload])))->ingest();

    expect(NewsItem::count())->toBe(1)
        ->and($second)->toHaveCount(0)
        ->and(NewsItemSource::count())->toBe(1);
});

it('keeps distinct stories as separate articles', function () {
    $created = (new NewsIngestor(stubProvider([
        item('Apple beats earnings', 'reuters', 'Reuters', 'https://reuters.test/apple'),
        item('Tesla recalls vehicles', 'cnbc', 'CNBC', 'https://cnbc.test/tesla'),
    ])))->ingest();

    expect($created)->toHaveCount(2)
        ->and(NewsItem::count())->toBe(2);
});

it('backfills an empty canonical summary from a later merged source', function () {
    $provider = stubProvider([
        item('Apple beats Q3 earnings estimates', 'reuters', 'Reuters', 'https://reuters.test/a'),
        item(
            'Q3 earnings estimates: Apple beats!',
            'bloomberght',
            'Bloomberg HT',
            'https://bloomberght.test/a',
            CarbonImmutable::parse('2026-06-17 15:00:00'),
            'Apple reported stronger revenue and margin than analysts expected.',
        ),
    ]);

    (new NewsIngestor($provider))->ingest();

    expect(NewsItem::first()?->summary)->toBe('Apple reported stronger revenue and margin than analysts expected.');
});
