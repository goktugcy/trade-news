<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $api_provider_id
 * @property string $name
 * @property string $model
 * @property bool $is_active
 * @property int $max_output_tokens
 * @property float|null $temperature
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ApiProvider $provider
 */
class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory;

    protected $fillable = [
        'api_provider_id',
        'name',
        'model',
        'is_active',
        'max_output_tokens',
        'temperature',
        'meta',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'max_output_tokens' => 160,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'max_output_tokens' => 'integer',
            'temperature' => 'float',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ApiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ApiProvider::class, 'api_provider_id');
    }
}
