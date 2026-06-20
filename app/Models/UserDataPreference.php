<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $auto_refresh_seconds
 * @property array<int, string>|null $preferred_markets
 * @property Carbon|null $onboarding_completed_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'auto_refresh_seconds', 'preferred_markets', 'onboarding_completed_at'])]
class UserDataPreference extends Model
{
    public const DEFAULT_AUTO_REFRESH_SECONDS = 60;

    /** @var array<int, int> */
    public const ALLOWED_AUTO_REFRESH_SECONDS = [0, 15, 30, 60, 300];

    /** @var array<string, mixed> */
    protected $attributes = [
        'auto_refresh_seconds' => self::DEFAULT_AUTO_REFRESH_SECONDS,
    ];

    protected function casts(): array
    {
        return [
            'auto_refresh_seconds' => 'integer',
            'preferred_markets' => 'array',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
