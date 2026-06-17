<?php

declare(strict_types=1);

namespace App\Enums;

enum ProviderStatus: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case Down = 'down';
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
            self::Unknown => 'slate',
        };
    }

    public function isHealthy(): bool
    {
        return $this === self::Operational;
    }
}
