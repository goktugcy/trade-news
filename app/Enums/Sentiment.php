<?php

declare(strict_types=1);

namespace App\Enums;

enum Sentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Tailwind-friendly token used by the frontend badge component.
     */
    public function color(): string
    {
        return match ($this) {
            self::Positive => 'emerald',
            self::Neutral => 'slate',
            self::Negative => 'rose',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Positive => '📈',
            self::Neutral => '➖',
            self::Negative => '📉',
        };
    }

    /**
     * Derive a sentiment from a normalized score in the range [-1, 1].
     */
    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 0.15 => self::Positive,
            $score <= -0.15 => self::Negative,
            default => self::Neutral,
        };
    }
}
