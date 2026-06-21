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
    public function stockList(bool $filterExchange = true): array
    {
        $rows = $this->get('/stock-list', timeout: 30);

        if (! is_array($rows)) {
            return [];
        }

        if (! $filterExchange) {
            return array_values(array_filter(
                $rows,
                fn (mixed $row): bool => is_array($row) && ($row['isActivelyTrading'] ?? true) !== false,
            ));
        }

        return array_values(array_filter(
            $rows,
            fn (mixed $row): bool => is_array($row) && $this->belongsToConfiguredExchange($row),
        ));
    }

    /**
     * Current S&P 500 constituents.
     *
     * @return array<int|string, mixed>
     */
    public function sp500Constituents(): array
    {
        $rows = $this->get('/sp500-constituent');

        return is_array($rows) ? $rows : [];
    }

    /**
     * Current ETF holdings for a fund such as QQQ or SPY.
     *
     * @return array<int|string, mixed>
     */
    public function etfHoldings(string $symbol): array
    {
        $rows = $this->get('/etf/holdings', ['symbol' => mb_strtoupper(trim($symbol))]);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Company profile for a symbol, or null if FMP has none.
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $symbol): ?array
    {
        $rows = $this->get('/profile', ['symbol' => $symbol], timeout: 15, retryDelay: 400);

        if (! is_array($rows)) {
            return null;
        }

        if (isset($rows[0]) && is_array($rows[0])) {
            return $rows[0];
        }

        return $this->isAssoc($rows) ? $rows : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = [], int $timeout = 20, int $retryDelay = 500): mixed
    {
        return Http::baseUrl($this->baseUrl)
            ->connectTimeout(5)
            ->timeout($timeout)
            ->retry(2, $retryDelay)
            ->throw()
            ->get($path, array_merge($query, ['apikey' => $this->apiKey]))
            ->json();
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
