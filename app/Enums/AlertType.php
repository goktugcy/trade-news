<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertType: string
{
    case PriceAbove = 'price_above';
    case PriceBelow = 'price_below';
    case PercentChange = 'percent_change';
    case VolumeIncrease = 'volume_increase';
    case NewsDetected = 'news_detected';
    case ImportantNews = 'important_news';

    public function label(): string
    {
        return match ($this) {
            self::PriceAbove => 'Price above',
            self::PriceBelow => 'Price below',
            self::PercentChange => 'Daily change % over',
            self::VolumeIncrease => 'Volume above',
            self::NewsDetected => 'Any news detected',
            self::ImportantNews => 'Important news detected',
        };
    }

    /**
     * Whether this alert type evaluates a numeric threshold.
     */
    public function needsThreshold(): bool
    {
        return match ($this) {
            self::NewsDetected => false,
            default => true, // important_news uses threshold as importance floor (default 50)
        };
    }

    /**
     * Price/volume alerts read the latest quote; news alerts read matched news.
     */
    public function isPriceType(): bool
    {
        return in_array($this, [self::PriceAbove, self::PriceBelow, self::PercentChange, self::VolumeIncrease], true);
    }

    public function isNewsType(): bool
    {
        return in_array($this, [self::NewsDetected, self::ImportantNews], true);
    }

    /**
     * Unit hint for the threshold input in the UI.
     */
    public function unit(): ?string
    {
        return match ($this) {
            self::PriceAbove, self::PriceBelow => 'price',
            self::PercentChange => '%',
            self::VolumeIncrease => 'shares',
            self::ImportantNews => 'min importance',
            self::NewsDetected => null,
        };
    }

    /**
     * @return array<int, array{value: string, label: string, needs_threshold: bool, unit: string|null}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'needs_threshold' => $t->needsThreshold(),
            'unit' => $t->unit(),
        ], self::cases());
    }
}
