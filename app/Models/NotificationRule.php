<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationInterval;
use Database\Factories\NotificationRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int $interval_minutes
 * @property array<int, string>|null $markets
 * @property array<int, string>|null $sentiments
 * @property bool $only_watchlist
 * @property int $min_importance
 * @property bool $is_active
 * @property Carbon|null $last_dispatched_at
 */
class NotificationRule extends Model
{
    /** @use HasFactory<NotificationRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'interval_minutes', 'markets', 'sentiments',
        'only_watchlist', 'min_importance', 'is_active', 'last_dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'markets' => 'array',
            'sentiments' => 'array',
            'only_watchlist' => 'boolean',
            'min_importance' => 'integer',
            'interval_minutes' => 'integer',
            'is_active' => 'boolean',
            'last_dispatched_at' => 'datetime',
        ];
    }

    public function interval(): NotificationInterval
    {
        return NotificationInterval::tryFrom($this->interval_minutes) ?? NotificationInterval::OneHour;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<NotificationRule>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<NotificationRule>  $query */
    public function scopeDueAt(Builder $query, \DateTimeInterface $moment): void
    {
        $minutesSinceMidnight = ((int) $moment->format('G') * 60) + (int) $moment->format('i');

        // interval_minutes that evenly divide the current minute-of-day are due.
        $query->whereRaw('? % interval_minutes = 0', [$minutesSinceMidnight]);
    }
}
