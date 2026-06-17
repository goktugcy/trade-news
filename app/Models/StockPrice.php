<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Timeframe;
use Database\Factories\StockPriceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $stock_id
 * @property Timeframe $timeframe
 * @property float $open
 * @property float $high
 * @property float $low
 * @property float $close
 * @property float $volume
 * @property Carbon $price_at
 */
class StockPrice extends Model
{
    /** @use HasFactory<StockPriceFactory> */
    use HasFactory;

    public const UPDATED_AT = null; // created_at only

    protected $fillable = [
        'stock_id', 'timeframe', 'open', 'high', 'low', 'close', 'volume', 'price_at',
    ];

    protected function casts(): array
    {
        return [
            'timeframe' => Timeframe::class,
            'open' => 'float',
            'high' => 'float',
            'low' => 'float',
            'close' => 'float',
            'volume' => 'float',
            'price_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /** @param  Builder<StockPrice>  $query */
    public function scopeTimeframe(Builder $query, Timeframe|string $tf): void
    {
        $query->where('timeframe', $tf instanceof Timeframe ? $tf->value : $tf);
    }
}
