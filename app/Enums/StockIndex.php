<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Equity indices whose constituents we track (synced from FMP). A stock can
 * belong to more than one — membership is stored per (stock, index) so the
 * same company appearing in both NASDAQ-100 and S&P 500 is a single stock row
 * with two membership rows.
 */
enum StockIndex: string
{
    case Nasdaq100 = 'nasdaq100';
    case Sp500 = 'sp500';

    public function label(): string
    {
        return match ($this) {
            self::Nasdaq100 => 'NASDAQ-100',
            self::Sp500 => 'S&P 500',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $i): string => $i->value, self::cases());
    }
}
