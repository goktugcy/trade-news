<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property bool $enabled
 * @property int|null $active_ai_model_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiModel|null $activeModel
 */
class AiSetting extends Model
{
    /** @use HasFactory<AiSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'enabled',
        'active_ai_model_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'active_ai_model_id' => 'integer',
        ];
    }

    public static function current(): self
    {
        $setting = self::query()->first();

        if ($setting instanceof self) {
            return $setting;
        }

        $setting = new self(['enabled' => false]);
        $setting->forceFill(['id' => 1])->save();

        return $setting;
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function activeModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'active_ai_model_id');
    }
}
