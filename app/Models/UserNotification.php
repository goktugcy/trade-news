<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationCategory;
use Database\Factories\UserNotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user-facing in-app notification (the platform inbox).
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationCategory $category
 * @property string $type
 * @property string $title
 * @property string|null $body
 * @property array<string, mixed>|null $data
 * @property string|null $action_url
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class UserNotification extends Model
{
    /** @use HasFactory<UserNotificationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'category', 'type', 'title', 'body', 'data', 'action_url', 'read_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<UserNotification>  $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }
}
