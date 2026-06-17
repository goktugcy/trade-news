<?php

declare(strict_types=1);

namespace App\Services\News;

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;
use App\Models\NewsItem;
use App\Models\NewsSource;
use Illuminate\Support\Collection;

/**
 * Fetches news centrally via the configured provider, dedupes by content hash,
 * and persists new items. Returns the freshly-created items so the caller can
 * queue matching + sentiment.
 */
class NewsIngestor
{
    public function __construct(
        private readonly NewsProviderInterface $provider,
    ) {}

    /**
     * @return Collection<int, NewsItem> newly inserted items
     */
    public function ingest(?Market $market = null, int $limit = 50): Collection
    {
        $payloads = $this->provider->fetchLatest($market, $limit);

        /** @var Collection<int, NewsItem> $created */
        $created = collect();
        $sourceCache = [];

        foreach ($payloads as $payload) {
            $hash = $payload->hash();

            if (NewsItem::query()->where('hash', $hash)->exists()) {
                continue;
            }

            $sourceId = $sourceCache[$payload->sourceKey]
                ??= $this->resolveSource($payload)->id;

            $created->push(NewsItem::create([
                'source_id' => $sourceId,
                'title' => $payload->title,
                'summary' => $payload->summary,
                'content' => $payload->content,
                'url' => $payload->url,
                'image_url' => $payload->imageUrl,
                'published_at' => $payload->publishedAt,
                'market' => $payload->market,
                'sentiment' => null,
                'sentiment_score' => null,
                'importance_score' => 0,
                'is_matched' => false,
                'hash' => $hash,
                'created_at' => now(),
            ]));
        }

        return $created;
    }

    private function resolveSource(NewsItemData $payload): NewsSource
    {
        return NewsSource::query()->firstOrCreate(
            ['key' => $payload->sourceKey],
            [
                'name' => $payload->sourceName ?? ucfirst($payload->sourceKey),
                'provider' => $this->provider->key(),
                'market' => $payload->market?->value,
                'is_active' => true,
            ],
        );
    }
}
