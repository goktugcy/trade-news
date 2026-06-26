<?php

declare(strict_types=1);

namespace App\Enums;

enum Market: string
{
    case NASDAQ = 'NASDAQ';

    public function label(): string
    {
        return 'NASDAQ';
    }

    public function currency(): string
    {
        return 'USD';
    }

    public function timezone(): string
    {
        return 'America/New_York';
    }

    /**
     * The TradingView exchange prefix used to build chart/quote widget symbols
     * (e.g. NASDAQ:AAPL).
     */
    public function tradingViewExchange(): string
    {
        return 'NASDAQ';
    }

    /**
     * Local market open/close in the market's own timezone (24h "H:i").
     *
     * @return array{open: string, close: string}
     */
    public function tradingHours(): array
    {
        return ['open' => '09:30', 'close' => '16:00'];
    }

    /**
     * Extended-hours (pre-market / after-hours) windows in the market's own
     * timezone.
     *
     * @return array{pre: array{open: string, close: string}, after: array{open: string, close: string}}
     */
    public function extendedHours(): array
    {
        return [
            'pre' => ['open' => '04:00', 'close' => '09:30'],
            'after' => ['open' => '16:00', 'close' => '20:00'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, currency: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'currency' => $m->currency(),
        ], self::cases());
    }
}
