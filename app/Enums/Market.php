<?php

declare(strict_types=1);

namespace App\Enums;

enum Market: string
{
    case BIST = 'BIST';
    case NASDAQ = 'NASDAQ';

    public function label(): string
    {
        return match ($this) {
            self::BIST => 'Borsa İstanbul',
            self::NASDAQ => 'NASDAQ',
        };
    }

    public function currency(): string
    {
        return match ($this) {
            self::BIST => 'TRY',
            self::NASDAQ => 'USD',
        };
    }

    public function timezone(): string
    {
        return match ($this) {
            self::BIST => 'Europe/Istanbul',
            self::NASDAQ => 'America/New_York',
        };
    }

    /**
     * Local market open/close in the market's own timezone (24h "H:i").
     *
     * @return array{open: string, close: string}
     */
    public function tradingHours(): array
    {
        return match ($this) {
            self::BIST => ['open' => '10:00', 'close' => '18:00'],
            self::NASDAQ => ['open' => '09:30', 'close' => '16:00'],
        };
    }

    /**
     * Extended-hours (pre-market / after-hours) windows in the market's own
     * timezone, or null when the exchange has no extended session (BIST).
     *
     * @return array{pre: array{open: string, close: string}, after: array{open: string, close: string}}|null
     */
    public function extendedHours(): ?array
    {
        return match ($this) {
            self::NASDAQ => [
                'pre' => ['open' => '04:00', 'close' => '09:30'],
                'after' => ['open' => '16:00', 'close' => '20:00'],
            ],
            self::BIST => null,
        };
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
