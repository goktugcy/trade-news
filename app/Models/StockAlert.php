<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertType;
use Database\Factories\StockAlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $stock_id
 * @property AlertType $type
 * @property float|null $threshold
 * @property bool $is_active
 * @property int $cooldown_minutes
 * @property Carbon|null $last_triggered_at
 * @property bool $notify_in_app
 * @property bool $notify_telegram
 */
class StockAlert extends Model
{
    /** @use HasFactory<StockAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'stock_id', 'type', 'threshold', 'is_active',
        'cooldown_minutes', 'last_triggered_at', 'notify_in_app', 'notify_telegram',
    ];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'threshold' => 'float',
            'is_active' => 'boolean',
            'cooldown_minutes' => 'integer',
            'last_triggered_at' => 'datetime',
            'notify_in_app' => 'boolean',
            'notify_telegram' => 'boolean',
        ];
    }

    /**
     * In cooldown if it triggered within cooldown_minutes of the given moment.
     */
    public function inCooldown(?\DateTimeInterface $moment = null): bool
    {
        if ($this->last_triggered_at === null) {
            return false;
        }

        $moment = $moment ? Carbon::instance($moment) : now();

        return $this->last_triggered_at->gt($moment->copy()->subMinutes(max(0, $this->cooldown_minutes)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /** @param  Builder<StockAlert>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
