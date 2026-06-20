<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-task AI configuration that layers on top of the global AiSetting switch.
 *
 * @property int $id
 * @property AiTask $task
 * @property bool $enabled
 * @property int|null $active_ai_model_id
 * @property string|null $fallback_behavior
 * @property array<string, mixed>|null $meta
 * @property-read AiModel|null $activeModel
 */
class AiTaskSetting extends Model
{
    protected $fillable = [
        'task',
        'enabled',
        'active_ai_model_id',
        'fallback_behavior',
        'meta',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'enabled' => 'boolean',
            'active_ai_model_id' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function activeModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'active_ai_model_id');
    }

    public static function forTask(AiTask $task): self
    {
        return self::query()->firstOrCreate(['task' => $task->value]);
    }
}
