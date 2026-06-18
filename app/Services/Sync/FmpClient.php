<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for Financial Modeling Prep (NASDAQ universe + company
 * profiles). No SDK — uses the Laravel HTTP client. Throws on transport/HTTP
 * failure so the caller (NasdaqSyncService) can record a failed SyncRun.
 *
 * @see https://site.financialmodelingprep.com/developer/docs
 */
class FmpClient
{
    private readonly string $baseUrl;

    public function __construct(
        private readonly ?string $apiKey,
        string $baseUrl = 'https://financialmodelingprep.com/stable',
        private readonly string $exchange = 'NASDAQ',
    ) {
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * All tradable symbols on the configured exchange.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stockList(): array
    {
        $rows = Http::baseUrl($this->baseUrl)
            ->connectTimeout(5)
            ->timeout(30)
            ->retry(2, 500)
            ->throw()
            ->get('/stock-list', ['apikey' => $this->apiKey])
            ->json();

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            fn (mixed $row): bool => is_array($row) && $this->belongsToConfiguredExchange($row),
        ));
    }

    /**
     * Company profile for a symbol, or null if FMP has none.
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $symbol): ?array
    {
        $rows = Http::baseUrl($this->baseUrl)
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 400)
            ->throw()
            ->get('/profile', ['symbol' => $symbol, 'apikey' => $this->apiKey])
            ->json();

        if (! is_array($rows)) {
            return null;
        }

        if (isset($rows[0]) && is_array($rows[0])) {
            return $rows[0];
        }

        return $this->isAssoc($rows) ? $rows : null;
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        if (str_contains($baseUrl, '/api/v3')) {
            return str_replace('/api/v3', '/stable', $baseUrl);
        }

        return $baseUrl;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function belongsToConfiguredExchange(array $row): bool
    {
        if (($row['isActivelyTrading'] ?? true) === false) {
            return false;
        }

        $exchange = mb_strtoupper($this->exchange);
        $candidates = array_filter([
            $row['exchangeShortName'] ?? null,
            $row['exchange'] ?? null,
            $row['exchangeName'] ?? null,
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        if ($candidates === []) {
            return true;
        }

        foreach ($candidates as $candidate) {
            if (str_contains(mb_strtoupper((string) $candidate), $exchange)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
