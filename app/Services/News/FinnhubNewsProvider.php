<?php

declare(strict_types=1);

namespace App\Services\News;

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        return 'finnhub-news';
    }

    public function fetchLatest(?Market $market = null, int $limit = 50): array
    {
        try {
            $response = $this->client()->get('/news', ['category' => 'general', 'token' => $this->apiKey]);
        } catch (ConnectionException $exception) {
            Log::warning('Finnhub news connection failed', ['error' => $exception->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('Finnhub news failed', [
                'status' => $response->status(),
                'body' => str($response->body())->limit(160)->toString(),
            ]);

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

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(
                [200, 500],
                when: fn (Throwable $exception): bool => $this->shouldRetry($exception),
                throw: false,
            );
    }

    private function shouldRetry(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
    }
}
