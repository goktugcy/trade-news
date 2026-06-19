<?php

declare(strict_types=1);

namespace App\Enums;

enum ProviderType: string
{
    case MarketData = 'market_data';
    case News = 'news';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::MarketData => 'Market Data',
            self::News => 'News',
            self::Ai => 'AI',
        };
    }
}
