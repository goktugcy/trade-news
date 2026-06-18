<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\Http;

/**
 * Bulk BIST100 quote source served through RapidAPI.
 */
class RapidApiBist100Client
{
    public const DEFAULT_BASE_URL = 'https://bist100-stock-data-15-minutes-late-live.p.rapidapi.com';

    public const RAPIDAPI_HOST = 'bist100-stock-data-15-minutes-late-live.p.rapidapi.com';

    private readonly string $baseUrl;

    public function __construct(
        private readonly ?string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->apiKey) !== '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function prices(): array
    {
        $payload = Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'x-rapidapi-host' => self::RAPIDAPI_HOST,
                'x-rapidapi-key' => (string) $this->apiKey,
            ])
            ->connectTimeout(5)
            ->timeout(30)
            ->retry(2, 500)
            ->throw()
            ->get('/bist100/prices')
            ->json();

        if (! is_array($payload)) {
            return [];
        }

        return $this->rows($payload);
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $payload): array
    {
        if (array_is_list($payload)) {
            return $this->onlyRows($payload);
        }

        foreach (['data', 'prices', 'results', 'stocks'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->onlyRows($payload[$key]);
            }
        }

        return $this->onlyRows([$payload]);
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function onlyRows(array $rows): array
    {
        return array_values(array_filter($rows, fn (mixed $row): bool => is_array($row)));
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        return preg_replace('#/bist100(?:/prices)?$#i', '', $baseUrl) ?: self::DEFAULT_BASE_URL;
    }
}
