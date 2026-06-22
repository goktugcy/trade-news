<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class UsIndexUniverseService
{
    public const SOURCE_AUTO = 'auto';

    public const SOURCE_FMP = 'fmp';

    public const SOURCE_FALLBACK = 'fallback';

    /**
     * @return array{symbols: array<int, string>, source: string, sp500_count: int, nasdaq100_count: int, fallback_reason?: string}
     */
    public function resolve(?string $source = null, bool $forceLive = false): array
    {
        $source = $this->normalizeSource($source ?: (string) config('tradenews.sync.us_universe.source', self::SOURCE_AUTO));

        if ($source === self::SOURCE_FALLBACK) {
            return $this->fallbackResult();
        }

        if ($source === self::SOURCE_FMP) {
            return $this->liveResult($forceLive);
        }

        try {
            return $this->liveResult($forceLive);
        } catch (Throwable $exception) {
            return array_merge($this->fallbackResult(), [
                'fallback_reason' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }

    public function __construct(private readonly FmpClient $fmp) {}

    public static function normalizeSymbol(string $symbol): string
    {
        $symbol = mb_strtoupper(trim($symbol));
        $symbol = preg_replace('/\[[^\]]+\]/', '', $symbol) ?? $symbol;

        if (str_contains($symbol, ':')) {
            $parts = explode(':', $symbol);
            $symbol = (string) end($parts);
        }

        $symbol = preg_replace('/\s+/', ' ', trim($symbol)) ?? $symbol;

        if (str_contains($symbol, ' ') && preg_match('/^[A-Z]{1,5}\s[A-Z]$/', $symbol) !== 1) {
            $symbol = preg_replace('/\s+.*$/', '', $symbol) ?? $symbol;
        }

        $symbol = str_replace(['.', '/', ' '], '-', $symbol);
        $symbol = preg_replace('/[^A-Z0-9-]/', '', $symbol) ?? '';
        $symbol = preg_replace('/-+/', '-', $symbol) ?? $symbol;

        return trim($symbol, '-');
    }

    /**
     * @return array{symbols: array<int, string>, source: string, sp500_count: int, nasdaq100_count: int}
     */
    private function liveResult(bool $forceLive): array
    {
        if (! $this->fmp->isConfigured()) {
            throw new RuntimeException('FMP API key is not configured.');
        }

        $cacheKey = 'sync.us_index_universe.fmp';

        if ($forceLive) {
            Cache::forget($cacheKey);
        }

        return Cache::remember(
            $cacheKey,
            max(60, (int) config('tradenews.sync.us_universe.cache_ttl_seconds', 43200)),
            fn (): array => $this->fetchLiveResult(),
        );
    }

    /**
     * @return array{symbols: array<int, string>, source: string, sp500_count: int, nasdaq100_count: int}
     */
    private function fetchLiveResult(): array
    {
        $sp500 = $this->symbolsFromRows($this->fmp->sp500Constituents(), ['symbol']);
        $nasdaq100 = $this->symbolsFromRows($this->fmp->etfHoldings(
            (string) config('tradenews.sync.us_universe.nasdaq100_etf', 'QQQ'),
        ), ['symbol', 'ticker', 'holdingSymbol', 'holding_symbol', 'stockSymbol', 'asset']);

        $this->ensureMinimumCount('S&P 500', $sp500, (int) config('tradenews.sync.us_universe.min_sp500_symbols', 400));
        $this->ensureMinimumCount('Nasdaq-100', $nasdaq100, (int) config('tradenews.sync.us_universe.min_nasdaq100_symbols', 80));

        return $this->result(self::SOURCE_FMP, $sp500, $nasdaq100);
    }

    /**
     * @return array{symbols: array<int, string>, source: string, sp500_count: int, nasdaq100_count: int}
     */
    private function fallbackResult(): array
    {
        $sp500 = $this->symbolsFromConfig(config('us_index_universe.fallback.sp500', []));
        $nasdaq100 = $this->symbolsFromConfig(config('us_index_universe.fallback.nasdaq100', []));

        return $this->result(self::SOURCE_FALLBACK, $sp500, $nasdaq100);
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    private function symbolsFromRows(array $rows, array $fields): array
    {
        $symbols = [];

        foreach ($this->unwrapRows($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $symbol = $this->symbolFromRow($row, $fields);

            if ($symbol !== null) {
                $symbols[$symbol] = true;
            }
        }

        ksort($symbols);

        return array_keys($symbols);
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return array<int, mixed>
     */
    private function unwrapRows(array $rows): array
    {
        if (array_is_list($rows)) {
            return $rows;
        }

        foreach (['data', 'holdings', 'results'] as $key) {
            if (isset($rows[$key]) && is_array($rows[$key])) {
                return array_values($rows[$key]);
            }
        }

        return [$rows];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $fields
     */
    private function symbolFromRow(array $row, array $fields): ?string
    {
        foreach ($fields as $field) {
            $candidate = $row[$field] ?? null;

            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            if ($field === 'asset' && preg_match('/\s/', trim($candidate)) === 1) {
                continue;
            }

            $symbol = self::normalizeSymbol($candidate);

            if ($this->isSymbol($symbol)) {
                return $symbol;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function symbolsFromConfig(mixed $configured): array
    {
        $symbols = [];

        foreach ((array) $configured as $item) {
            foreach (preg_split('/\s+/', (string) $item) ?: [] as $symbol) {
                $symbol = self::normalizeSymbol($symbol);

                if ($this->isSymbol($symbol)) {
                    $symbols[$symbol] = true;
                }
            }
        }

        ksort($symbols);

        return array_keys($symbols);
    }

    /**
     * @param  array<int, string>  $symbols
     */
    private function ensureMinimumCount(string $label, array $symbols, int $minimum): void
    {
        if (count($symbols) < $minimum) {
            throw new RuntimeException("{$label} universe returned too few symbols.");
        }
    }

    /**
     * @param  array<int, string>  $sp500
     * @param  array<int, string>  $nasdaq100
     * @return array{symbols: array<int, string>, source: string, sp500_count: int, nasdaq100_count: int, sp500_symbols: array<int, string>, nasdaq100_symbols: array<int, string>}
     */
    private function result(string $source, array $sp500, array $nasdaq100): array
    {
        $symbols = array_fill_keys([...$sp500, ...$nasdaq100], true);
        ksort($symbols);

        return [
            'symbols' => array_keys($symbols),
            'source' => $source,
            'sp500_count' => count($sp500),
            'nasdaq100_count' => count($nasdaq100),
            'sp500_symbols' => $sp500,
            'nasdaq100_symbols' => $nasdaq100,
        ];
    }

    private function normalizeSource(string $source): string
    {
        $source = mb_strtolower(trim($source));

        return in_array($source, [self::SOURCE_AUTO, self::SOURCE_FMP, self::SOURCE_FALLBACK], true)
            ? $source
            : self::SOURCE_AUTO;
    }

    private function isSymbol(string $symbol): bool
    {
        return preg_match('/^[A-Z][A-Z0-9-]{0,11}$/', $symbol) === 1;
    }
}
