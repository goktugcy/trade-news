<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProviderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $api_provider_id
 * @property ProviderStatus|null $from_status
 * @property ProviderStatus $to_status
 * @property string|null $reason
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 */
class ProviderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'api_provider_id', 'from_status', 'to_status', 'reason', 'context', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => ProviderStatus::class,
            'to_status' => ProviderStatus::class,
            'context' => 'array',
            'created_at' => 'datetime',
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
