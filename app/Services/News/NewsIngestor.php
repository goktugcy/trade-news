<?php

declare(strict_types=1);

namespace App\Services\News;

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;
use App\Models\NewsItem;
use App\Models\NewsItemSource;
use App\Models\NewsSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fetches news centrally via a provider, then for each article:
 *   1. skips it if that exact source already delivered it (original_hash),
 *   2. merges it into an existing article when another source already reported
 *      the same story (normalized title within a ±48h window, same market),
 *   3. otherwise creates a new canonical article.
 *
 * Every original source is tracked in news_item_sources so merged articles keep
 * full attribution. Returns only newly-created canonical items so the caller can
 * queue sentiment / matching / summary for them.
 */
class NewsIngestor
{
    private const MERGE_WINDOW_HOURS = 48;

    private readonly TitleNormalizer $normalizer;

    public function __construct(
        private readonly NewsProviderInterface $provider,
        ?TitleNormalizer $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?? new TitleNormalizer;
    }

    /**
     * @return Collection<int, NewsItem> newly inserted canonical items
     */
    public function ingest(?Market $market = null, int $limit = 50): Collection
    {
        $payloads = $this->provider->fetchLatest($market, $limit);

        /** @var Collection<int, NewsItem> $created */
        $created = collect();
        $sourceCache = [];

        foreach ($payloads as $payload) {
            $originalHash = $payload->hash();

            // Already ingested from this exact source? skip.
            if (NewsItemSource::query()->where('original_hash', $originalHash)->exists()) {
                continue;
            }

            $source = $sourceCache[$payload->sourceKey] ??= $this->resolveSource($payload);
            $normalized = $this->normalizer->fingerprint($payload->title, $payload->market?->value);

            try {
                $canonical = $this->findCanonical($payload, $normalized);

                if ($canonical !== null) {
                    $this->mergeInto($canonical, $source, $payload, $originalHash);
                } else {
                    $created->push($this->createCanonical($source, $payload, $originalHash, $normalized));
                }
            } catch (QueryException) {
                // Race with another worker on the unique original_hash — skip.
                continue;
            }
        }

        return $created;
    }

    /**
     * Find an existing canonical article that represents the same story.
     */
    private function findCanonical(NewsItemData $payload, string $normalized): ?NewsItem
    {
        $query = NewsItem::query()->where('normalized_hash', $normalized);

        $payload->market !== null
            ? $query->where('market', $payload->market->value)
            : $query->whereNull('market');

        if ($payload->publishedAt !== null) {
            $query->whereBetween('published_at', [
                $payload->publishedAt->subHours(self::MERGE_WINDOW_HOURS),
                $payload->publishedAt->addHours(self::MERGE_WINDOW_HOURS),
            ]);
        }

        return $query->orderBy('id')->first();
    }

    private function createCanonical(NewsSource $source, NewsItemData $payload, string $originalHash, string $normalized): NewsItem
    {
        return DB::transaction(function () use ($source, $payload, $originalHash, $normalized): NewsItem {
            $item = NewsItem::create([
                'source_id' => $source->id,
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
                'hash' => $originalHash,
                'normalized_hash' => $normalized,
                'source_count' => 1,
                'created_at' => now(),
            ]);

            $this->attachSource($item, $source, $payload, $originalHash);

            return $item;
        });
    }

    private function mergeInto(NewsItem $canonical, NewsSource $source, NewsItemData $payload, string $originalHash): void
    {
        DB::transaction(function () use ($canonical, $source, $payload, $originalHash): void {
            $this->attachSource($canonical, $source, $payload, $originalHash);

            $updates = [
                'source_count' => $canonical->source_count + 1,
                // Corroboration from another outlet nudges importance up.
                'importance_score' => min(100, $canonical->importance_score + 5),
            ];

            // Keep the earliest publish time we have seen.
            if ($payload->publishedAt !== null
                && ($canonical->published_at === null || $payload->publishedAt->lt($canonical->published_at))) {
                $updates['published_at'] = $payload->publishedAt;
            }

            // Backfill richer content/image from a later source if missing.
            if (blank($canonical->summary) && filled($payload->summary)) {
                $updates['summary'] = $payload->summary;
            }
            if (blank($canonical->content) && filled($payload->content)) {
                $updates['content'] = $payload->content;
            }
            if (blank($canonical->image_url) && filled($payload->imageUrl)) {
                $updates['image_url'] = $payload->imageUrl;
            }

            $canonical->forceFill($updates)->save();
        });
    }

    private function attachSource(NewsItem $item, NewsSource $source, NewsItemData $payload, string $originalHash): void
    {
        $item->sources()->create([
            'news_source_id' => $source->id,
            'url' => $payload->url,
            'published_at' => $payload->publishedAt,
            'original_hash' => $originalHash,
            'created_at' => now(),
        ]);
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
