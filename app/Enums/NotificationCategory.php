<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationCategory: string
{
    case Alert = 'alert';     // user custom price/volume/news alerts
    case News = 'news';       // news digests
    case System = 'system';   // generic platform messages
    case Provider = 'provider'; // provider status changes (admin)
    case Sync = 'sync';       // sync job outcomes (admin)

    public function label(): string
    {
        return match ($this) {
            self::Alert => 'Alerts',
            self::News => 'News',
            self::System => 'System',
            self::Provider => 'Providers',
            self::Sync => 'Sync',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Alert => 'amber',
            self::News => 'sky',
            self::System => 'slate',
            self::Provider => 'violet',
            self::Sync => 'emerald',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
