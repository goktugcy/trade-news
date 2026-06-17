<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TelegramIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $chat_id
 * @property string|null $telegram_username
 * @property string|null $connection_code
 * @property Carbon|null $code_expires_at
 * @property bool $is_enabled
 * @property Carbon|null $connected_at
 */
class TelegramIntegration extends Model
{
    /** @use HasFactory<TelegramIntegrationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'chat_id', 'telegram_username', 'connection_code',
        'code_expires_at', 'is_enabled', 'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'code_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'is_enabled' => 'boolean',
        ];
    }

    public function isConnected(): bool
    {
        return ! empty($this->chat_id);
    }

    /**
     * Ready to actually receive alerts.
     */
    public function isActive(): bool
    {
        return $this->isConnected() && $this->is_enabled;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
