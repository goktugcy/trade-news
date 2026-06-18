<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $auto_refresh_seconds
 * @property-read User $user
 */
#[Fillable(['user_id', 'auto_refresh_seconds'])]
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
