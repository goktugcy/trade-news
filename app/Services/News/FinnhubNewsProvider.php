<?php

declare(strict_types=1);

namespace App\Services\News;

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Real news integration against Finnhub's general market-news endpoint.
 *
 * @see https://finnhub.io/docs/api/market-news
 */
class FinnhubNewsProvider implements NewsProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://finnhub.io/api/v1',
    ) {}

    public function key(): string
    {
        return 'finnhub';
    }

    public function fetchLatest(?Market $market = null, int $limit = 50): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->timeout(10)
            ->retry(2, 200)
            ->get('/news', ['category' => 'general', 'token' => $this->apiKey]);

        if ($response->failed()) {
            return [];
        }

        $rows = array_slice((array) $response->json(), 0, $limit);
        $items = [];

        foreach ($rows as $row) {
            if (empty($row['headline'])) {
                continue;
            }

            $items[] = new NewsItemData(
                title: (string) $row['headline'],
                summary: $row['summary'] ?? null,
                content: $row['summary'] ?? null,
                url: $row['url'] ?? null,
                imageUrl: $row['image'] ?? null,
                publishedAt: isset($row['datetime'])
                    ? CarbonImmutable::createFromTimestamp((int) $row['datetime'])
                    : null,
                // Finnhub general news isn't market-scoped; default to NASDAQ universe.
                market: $market ?? Market::NASDAQ,
                sourceKey: 'finnhub',
                sourceName: $row['source'] ?? 'Finnhub',
                relatedSymbols: isset($row['related']) ? array_filter(explode(',', (string) $row['related'])) : [],
            );
        }

        return $items;
    }
}
