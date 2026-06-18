<?php

declare(strict_types=1);

namespace App\Services\News;

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;
use App\Models\NewsSource;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laminas\Feed\Reader\Entry\AbstractEntry;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\Reader;
use Throwable;

/**
 * Aggregates many RSS/Atom feeds (Reuters, MarketWatch, CNBC, KAP, Bloomberg HT,
 * …) into normalized NewsItemData. Each configured feed carries its own source
 * key + market so every origin is tracked even after cross-source merging.
 *
 * Defensive by design: a single failing or malformed feed is logged and skipped
 * — it never aborts the whole fetch. Only ever called from a queued job.
 */
class RssNewsProvider implements NewsProviderInterface
{
    /**
     * @param  array<int, array{key: string, name: string, market: ?string, url?: ?string, homepage_url?: ?string}>|null  $feeds
     */
    public function __construct(
        private readonly ?array $feeds = null,
        private readonly int $timeout = 12,
        private readonly int $perFeedLimit = 40,
    ) {}

    public function key(): string
    {
        return 'rss';
    }

    public function fetchLatest(?Market $market = null, int $limit = 50): array
    {
        $items = [];

        foreach ($this->feeds() as $feed) {
            if (empty($feed['url'] ?? null)) {
                continue;
            }

            // When a market is requested, only pull feeds for that market (a
            // feed with null market is treated as global → included for NASDAQ).
            $feedMarket = ($feed['market'] ?? null) !== null ? Market::tryFrom((string) $feed['market']) : null;

            if ($market !== null && $feedMarket !== null && $feedMarket !== $market) {
                continue;
            }

            if ($market === Market::BIST && $feedMarket === null) {
                continue; // global feeds attach to the NASDAQ/global run only
            }

            foreach ($this->fetchFeed($feed, $feedMarket) as $item) {
                $items[] = $item;
            }
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * @param  array{key: string, name: string, market: ?string, url?: ?string, homepage_url?: ?string}  $feed
     * @return array<int, NewsItemData>
     */
    private function fetchFeed(array $feed, ?Market $feedMarket): array
    {
        $url = (string) ($feed['url'] ?? '');

        if ($url === '') {
            return [];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->retry(2, 250)
                ->withHeaders(['User-Agent' => 'TradeNewsBot/1.0 (+rss-aggregator)'])
                ->get($url);

            if ($response->failed() || trim($response->body()) === '') {
                return [];
            }

            $channel = Reader::importString($response->body());
            $items = [];
            $count = 0;

            foreach ($channel as $entry) {
                /** @var EntryInterface $entry */
                if ($count >= $this->perFeedLimit) {
                    break;
                }

                $data = $this->mapEntry($entry, $feed, $feedMarket);

                if ($data !== null) {
                    $items[] = $data;
                    $count++;
                }
            }

            return $items;
        } catch (Throwable $e) {
            Log::warning('RSS feed fetch/parse failed', [
                'feed' => $feed['key'],
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array{key: string, name: string, market: ?string, url?: ?string, homepage_url?: ?string}  $feed
     */
    private function mapEntry(EntryInterface $entry, array $feed, ?Market $feedMarket): ?NewsItemData
    {
        $title = trim((string) $entry->getTitle());

        if ($title === '') {
            return null;
        }

        $description = $this->clean($entry->getDescription());
        $content = (string) $entry->getContent();
        $cleanContent = $this->clean($content);

        return new NewsItemData(
            title: $title,
            summary: $description ?? $cleanContent,
            content: trim($content) !== '' ? $content : $description,
            url: $entry->getLink() ?: null,
            imageUrl: $this->extractImage($entry),
            publishedAt: $this->extractDate($entry),
            market: $feedMarket,
            sourceKey: $feed['key'],
            sourceName: $feed['name'],
        );
    }

    private function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    /**
     * @return array<int, array{key: string, name: string, market: ?string, url?: ?string, homepage_url?: ?string}>
     */
    private function feeds(): array
    {
        if ($this->feeds !== null) {
            return $this->feeds;
        }

        $configuredFeeds = collect((array) config('tradenews.news.providers.rss.feeds', []))
            ->filter(fn (mixed $feed): bool => is_array($feed) && isset($feed['key']))
            ->keyBy(fn (array $feed): string => (string) $feed['key']);

        return NewsSource::query()
            ->where('provider', 'rss')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (NewsSource $source) use ($configuredFeeds): array {
                $configuredFeed = $configuredFeeds->get($source->key, []);

                return [
                    'key' => $source->key,
                    'name' => $source->name,
                    'market' => $source->market,
                    'url' => $source->feed_url ?: (is_array($configuredFeed) ? ($configuredFeed['url'] ?? null) : null),
                    'homepage_url' => $source->homepage_url ?: (is_array($configuredFeed) ? ($configuredFeed['homepage_url'] ?? null) : null),
                ];
            })
            ->all();
    }

    private function extractImage(EntryInterface $entry): ?string
    {
        $enclosure = $entry->getEnclosure();

        if ($enclosure !== null) {
            $type = strtolower((string) ($enclosure->type ?? ''));
            $url = $enclosure->url ?? $enclosure->href ?? null;

            if (($type === '' || str_starts_with($type, 'image/')) && ($normalized = $this->normalizeImageUrl($url)) !== null) {
                return $normalized;
            }
        }

        foreach ($this->xmlImageCandidates($entry) as $candidate) {
            if (($normalized = $this->normalizeImageUrl($candidate)) !== null) {
                return $normalized;
            }
        }

        foreach ($this->htmlImageCandidates($entry->getDescription()) as $candidate) {
            if (($normalized = $this->normalizeImageUrl($candidate)) !== null) {
                return $normalized;
            }
        }

        foreach ($this->htmlImageCandidates($entry->getContent()) as $candidate) {
            if (($normalized = $this->normalizeImageUrl($candidate)) !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function xmlImageCandidates(EntryInterface $entry): array
    {
        if (! $entry instanceof AbstractEntry) {
            return [];
        }

        $element = $entry->getElement();

        if (! $element instanceof DOMElement || ! $element->ownerDocument instanceof DOMDocument) {
            return [];
        }

        $xpath = new DOMXPath($element->ownerDocument);
        $queries = [
            './/*[local-name()="thumbnail" and namespace-uri()="http://search.yahoo.com/mrss/"]/@url',
            './/*[local-name()="content" and namespace-uri()="http://search.yahoo.com/mrss/" and (@medium="image" or starts-with(@type, "image/"))]/@url',
            './*[local-name()="image"]/@url',
            './*[local-name()="image"]/@href',
            './*[local-name()="image"]/text()',
            './*[local-name()="image"]/*[local-name()="url"]/text()',
            './/*[local-name()="image"]/@url',
            './/*[local-name()="image"]/@href',
        ];

        $candidates = [];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query, $element);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if ($node instanceof DOMNode && trim((string) $node->nodeValue) !== '') {
                    $candidates[] = trim((string) $node->nodeValue);
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<int, string>
     */
    private function htmlImageCandidates(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html);

            $candidates = [];

            foreach ($document->getElementsByTagName('img') as $image) {
                $src = $image->getAttribute('src');

                if (trim($src) !== '') {
                    $candidates[] = $src;
                }
            }

            return $candidates;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalizeImageUrl(mixed $url): ?string
    {
        if (! is_string($url) && ! is_numeric($url)) {
            return null;
        }

        $url = trim(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function extractDate(EntryInterface $entry): ?CarbonImmutable
    {
        $date = $entry->getDateModified() ?? $entry->getDateCreated();

        if ($date === null) {
            return null;
        }

        // Laminas returns a DateTime; normalize to UTC immutable.
        return CarbonImmutable::instance($date)->utc();
    }
}
