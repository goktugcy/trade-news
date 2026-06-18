<?php

declare(strict_types=1);

namespace App\Enums;

enum MarketSession: string
{
    case Open = 'open';
    case Closed = 'closed';
    case PreMarket = 'pre_market';
    case AfterHours = 'after_hours';
    case Holiday = 'holiday';
    case Weekend = 'weekend';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::PreMarket => 'Pre-market',
            self::AfterHours => 'After hours',
            self::Holiday => 'Holiday',
            self::Weekend => 'Weekend',
        };
    }

    /**
     * Tailwind colour token used by the frontend badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'emerald',
            self::PreMarket, self::AfterHours => 'amber',
            self::Closed, self::Weekend => 'slate',
            self::Holiday => 'violet',
        };
    }

    /**
     * Whether the regular trading session is currently active.
     */
    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
