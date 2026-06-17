<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Run log / heartbeat for scheduled jobs.
 *
 * @property int $id
 * @property string $name
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property string|null $message
 * @property array<string, mixed>|null $meta
 */
class SystemJob extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'name', 'status', 'started_at', 'finished_at', 'duration_ms', 'message', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * Run a unit of work while recording timing + status into the heartbeat log.
     *
     * @template TReturn
     *
     * @param  callable(self): TReturn  $callback
     * @return TReturn
     */
    public static function track(string $name, callable $callback): mixed
    {
        $job = self::create([
            'name' => $name,
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $start = microtime(true);

        try {
            $result = $callback($job);

            $job->update([
                'status' => self::STATUS_SUCCESS,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $job->update([
                'status' => self::STATUS_FAILED,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }
    }
}
