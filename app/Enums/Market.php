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
