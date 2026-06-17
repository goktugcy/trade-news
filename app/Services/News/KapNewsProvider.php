<?php

declare(strict_types=1);

namespace App\Services\News;

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort integration with KAP (Kamuyu Aydınlatma Platformu) — the Turkish
 * public-disclosure platform — for BIST company filings.
 *
 * KAP does not publish a stable, documented public JSON API, so this provider
 * is defensive: it posts to the disclosure query endpoint, normalizes whatever
 * comes back, and degrades gracefully (returns []) on any failure. Only ever
 * invoked from the scheduled FetchMarketNewsJob, never inline in a request.
 */
class KapNewsProvider implements NewsProviderInterface
{
    public function __construct(
        private readonly string $baseUrl = 'https://www.kap.org.tr',
    ) {}

    public function key(): string
    {
        return 'kap';
    }

    public function fetchLatest(?Market $market = null, int $limit = 50): array
    {
        // KAP only covers BIST; skip when the caller wants a different market.
        if ($market !== null && $market !== Market::BIST) {
            return [];
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout(12)
                ->acceptJson()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('/tr/api/memberDisclosureQuery', [
                    'fromDate' => CarbonImmutable::now()->subDay()->format('Y-m-d'),
                    'toDate' => CarbonImmutable::now()->format('Y-m-d'),
                    'mainSector' => '',
                    'disclosureClass' => '',
                ]);

            if ($response->failed()) {
                return [];
            }

            $rows = array_slice((array) $response->json(), 0, $limit);
            $items = [];

            foreach ($rows as $row) {
                $title = $row['title'] ?? $row['kapTitle'] ?? null;

                if (! $title) {
                    continue;
                }

                $disclosureId = $row['disclosureIndex'] ?? $row['id'] ?? null;

                $items[] = new NewsItemData(
                    title: (string) $title,
                    summary: $row['summary'] ?? ($row['stockCodes'] ?? null),
                    content: null,
                    url: $disclosureId ? "{$this->baseUrl}/tr/Bildirim/{$disclosureId}" : $this->baseUrl,
                    imageUrl: null,
                    publishedAt: isset($row['publishDate'])
                        ? CarbonImmutable::parse($row['publishDate'])
                        : CarbonImmutable::now(),
                    market: Market::BIST,
                    sourceKey: 'kap',
                    sourceName: 'KAP',
                    relatedSymbols: isset($row['stockCodes'])
                        ? array_map('trim', explode(',', (string) $row['stockCodes']))
                        : [],
                );
            }

            return $items;
        } catch (\Throwable $e) {
            Log::warning('KAP fetch failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
