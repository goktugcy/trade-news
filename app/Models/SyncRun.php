<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string|null $provider_key
 * @property string $status
 * @property int $processed
 * @property int $created_count
 * @property int $updated_count
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 * @property array<string, mixed>|null $meta
 */
class SyncRun extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type', 'provider_key', 'status', 'processed', 'created_count',
        'updated_count', 'started_at', 'finished_at', 'error', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'processed' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Most recent run of a type with the given status (success/failed).
     */
    public static function lastOfStatus(string $type, string $status): ?self
    {
        return self::query()
            ->where('type', $type)
            ->where('status', $status)
            ->latest('id')
            ->first();
    }
}
