<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\QuoteData;
use App\Enums\Timeframe;
use App\Models\Stock;
use App\Services\Sync\FmpClient;
use Carbon\CarbonImmutable;

/**
 * Financial Modeling Prep latest-quote provider. FMP's strength is its batch
 * quote endpoint, so the scheduled sync (FmpQuoteSyncService) pulls many symbols
 * per request; this class supplies the per-symbol path for the provider
 * interface / fallback chain and the shared FMP row → QuoteData mapper.
 *
 * Charts come from TradingView, so FMP never supplies internal candles.
 */
class FmpQuoteProvider implements MarketDataProviderInterface
{
    public function __construct(private readonly FmpClient $client) {}

    public function key(): string
    {
        return 'fmp';
    }

    public function getQuote(Stock $stock): ?QuoteData
    {
        $rows = $this->client->batchQuote([$stock->symbol]);

        return isset($rows[0]) && is_array($rows[0]) ? self::mapRow($rows[0]) : null;
    }

    /**
     * FMP supplies no internal candles here — TradingView handles charting.
     *
     * @return array<int, never>
     */
    public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array
    {
        return [];
    }

    /**
     * Map one FMP batch-quote row to a normalized QuoteData, tolerating the
     * field-name variants FMP uses across plans/endpoints. Null when the row has
     * no usable symbol/price.
     *
     * @param  array<string, mixed>  $row
     */
    public static function mapRow(array $row): ?QuoteData
    {
        $symbol = isset($row['symbol']) ? mb_strtoupper(trim((string) $row['symbol'])) : '';
        $price = self::number($row['price'] ?? null);

        if ($symbol === '' || $price === null) {
            return null;
        }

        $previousClose = self::number($row['previousClose'] ?? null) ?? $price;
        $timestamp = $row['timestamp'] ?? null;

        $at = is_numeric($timestamp)
            ? CarbonImmutable::createFromTimestamp((int) $timestamp)
            : CarbonImmutable::now();

        return new QuoteData(
            symbol: $symbol,
            price: $price,
            open: self::number($row['open'] ?? null) ?? $price,
            high: self::number($row['dayHigh'] ?? null) ?? $price,
            low: self::number($row['dayLow'] ?? null) ?? $price,
            previousClose: $previousClose,
            volume: self::number($row['volume'] ?? null) ?? 0.0,
            at: $at,
            averageVolume: self::number($row['avgVolume'] ?? $row['averageVolume'] ?? null),
        );
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
