<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\Enums\StockIndex;
use App\Models\Stock;
use App\Services\Providers\ProviderHealthService;
use App\Services\Sync\FmpClient;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Syncs latest quotes for index-member stocks (NASDAQ-100 + S&P 500 only) via
 * FMP's batch endpoint — one HTTP request per chunk, never per stock. Caches the
 * quote in Redis and persists it locally through the shared MarketDataIngestor,
 * and records success/failure against the 'fmp' provider health row.
 *
 * Run from SyncQuotesJob / the scheduled command — never inside an HTTP request.
 */
class FmpQuoteSyncService
{
    public const PROVIDER_KEY = 'fmp';

    public function __construct(
        private readonly FmpClient $client,
        private readonly ProviderHealthService $health,
    ) {}

    /**
     * @return int number of stocks whose quote was refreshed
     */
    public function sync(): int
    {
        if (! $this->client->isConfigured()) {
            return 0;
        }

        $stocks = $this->indexMemberStocks();

        if ($stocks->isEmpty()) {
            return 0;
        }

        $ingestor = new MarketDataIngestor(new FmpQuoteProvider($this->client));
        $batchSize = max(1, (int) config('tradenews.market_data.providers.fmp.quote_batch', 100));
        $synced = 0;

        foreach ($stocks->chunk($batchSize) as $chunk) {
            $synced += $this->syncChunk($chunk, $ingestor);
        }

        return $synced;
    }

    /**
     * @param  Collection<int, Stock>  $chunk
     */
    private function syncChunk(Collection $chunk, MarketDataIngestor $ingestor): int
    {
        $symbols = $chunk->pluck('symbol')->all();

        try {
            $rows = $this->client->batchQuote($symbols);
        } catch (Throwable $e) {
            $this->health->recordFailure(self::PROVIDER_KEY, $e->getMessage());

            return 0;
        }

        $quotes = [];

        foreach ($rows as $row) {
            $quote = FmpQuoteProvider::mapRow($row);

            if ($quote !== null) {
                $quotes[$quote->symbol] = $quote;
            }
        }

        $synced = 0;

        foreach ($chunk as $stock) {
            $quote = $quotes[mb_strtoupper($stock->symbol)] ?? null;

            if ($quote === null) {
                continue;
            }

            $ingestor->cacheQuote($stock, $quote, self::PROVIDER_KEY);
            $ingestor->upsertQuoteCandles($stock, $quote, self::PROVIDER_KEY);
            $synced++;
        }

        $this->health->recordSuccess(self::PROVIDER_KEY, 'batch_quotes');

        return $synced;
    }

    /**
     * Active stocks that currently belong to NASDAQ-100 or S&P 500 — the only
     * universe we pull quotes for (no full-NASDAQ fetch).
     *
     * @return Collection<int, Stock>
     */
    private function indexMemberStocks(): Collection
    {
        return Stock::query()
            ->active()
            ->whereHas('indexMemberships', fn ($q) => $q
                ->whereIn('index_key', [StockIndex::Nasdaq100->value, StockIndex::Sp500->value])
                ->where('is_current', true))
            ->get(['id', 'symbol', 'name', 'currency']);
    }
}
