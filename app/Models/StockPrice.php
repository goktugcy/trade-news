<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Timeframe;
use App\Services\Providers\ApiProviderRegistry;
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
 * @property string|null $provider_key
 * @property string $source_kind
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

    public const SOURCE_CANDLE = 'candle';

    public const SOURCE_QUOTE = 'quote';

    public const SOURCE_SYNTHETIC = 'synthetic';

    public const PROVIDER_MANUAL_CSV = 'manual-csv';

    public const PROVIDER_BULK_CSV = 'bulk-csv';

    public const PROVIDER_STOOQ_UPLOAD = 'stooq-upload';

    protected $fillable = [
        'stock_id', 'timeframe', 'provider_key', 'source_kind',
        'open', 'high', 'low', 'close', 'volume', 'price_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'source_kind' => self::SOURCE_CANDLE,
    ];

    protected function casts(): array
    {
        return [
            'timeframe' => Timeframe::class,
            'source_kind' => 'string',
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

    /** @param  Builder<StockPrice>  $query */
    public function scopeWithoutSyntheticWhenApiActive(Builder $query, bool $apiActive): void
    {
        if (! $apiActive) {
            return;
        }

        $query
            ->whereNotNull('provider_key')
            ->whereNotIn('provider_key', ApiProviderRegistry::syntheticKeys())
            ->where('source_kind', '!=', self::SOURCE_SYNTHETIC);
    }
}
