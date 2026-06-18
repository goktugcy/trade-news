<?php

declare(strict_types=1);

namespace App\Enums;

enum ProviderStatus: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case Down = 'down';
    case Disabled = 'disabled';
    case Unknown = 'unknown';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Operational => 'emerald',
            self::Degraded => 'amber',
            self::Down => 'rose',
            self::Disabled => 'slate',
            self::Unknown => 'slate',
        };
    }

    public function isHealthy(): bool
    {
        return $this === self::Operational;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
