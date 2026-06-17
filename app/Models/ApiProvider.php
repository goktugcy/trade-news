<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use Database\Factories\ApiProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ProviderType $type
 * @property ProviderStatus $status
 * @property bool $is_active
 * @property string|null $base_url
 * @property int $priority
 * @property Carbon|null $last_checked_at
 * @property int|null $last_latency_ms
 * @property string|null $last_error
 * @property array<string, mixed>|null $meta
 */
class ApiProvider extends Model
{
    /** @use HasFactory<ApiProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'type', 'status', 'is_active', 'base_url',
        'priority', 'last_checked_at', 'last_latency_ms', 'last_error', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'status' => ProviderStatus::class,
            'is_active' => 'boolean',
            'priority' => 'integer',
            'last_checked_at' => 'datetime',
            'last_latency_ms' => 'integer',
            'meta' => 'array',
        ];
    }
}
