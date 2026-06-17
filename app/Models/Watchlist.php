<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WatchlistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single followed stock in a user's watchlist.
 *
 * @property int $id
 * @property int $user_id
 * @property int $stock_id
 * @property bool $alerts_enabled
 * @property int $position
 */
class Watchlist extends Model
{
    /** @use HasFactory<WatchlistFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'stock_id', 'alerts_enabled', 'position',
    ];

    protected function casts(): array
    {
        return [
            'alerts_enabled' => 'boolean',
        ];
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
}
