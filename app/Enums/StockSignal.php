<?php

declare(strict_types=1);

namespace App\Enums;

enum StockSignal: string
{
    case Bullish = 'bullish';
    case Neutral = 'neutral';
    case Bearish = 'bearish';

    /**
     * User-facing AI Outlook label. Stored values stay bullish/neutral/bearish
     * for backward compatibility, but we present a neutral Positive/Neutral/Negative
     * outlook rather than buy/sell-flavoured signal wording.
     */
    public function label(): string
    {
        return match ($this) {
            self::Bullish => 'Positive',
            self::Neutral => 'Neutral',
            self::Bearish => 'Negative',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Bullish => 'emerald',
            self::Neutral => 'slate',
            self::Bearish => 'rose',
        };
    }

    public static function fromLoose(?string $value): self
    {
        return match (mb_strtolower(trim((string) $value))) {
            'bullish', 'positive', 'buy', 'up' => self::Bullish,
            'bearish', 'negative', 'sell', 'down' => self::Bearish,
            default => self::Neutral,
        };
    }
}
