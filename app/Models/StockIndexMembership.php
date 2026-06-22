<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Membership of a stock in an equity index (NASDAQ-100 / S&P 500). Rows are
 * kept after a company leaves an index (is_current=false, removed_at set) to
 * preserve historical references.
 *
 * @property int $id
 * @property int $stock_id
 * @property string $index_key
 * @property bool $is_current
 * @property Carbon|null $added_at
 * @property Carbon|null $removed_at
 */
class StockIndexMembership extends Model
{
    protected $fillable = [
        'stock_id', 'index_key', 'is_current', 'added_at', 'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'added_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
