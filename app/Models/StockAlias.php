<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One normalized alias for a stock in the deterministic matching index. Built
 * and maintained by StockAliasService; never edited by hand.
 *
 * @property int $id
 * @property int $stock_id
 * @property string $alias
 * @property string $normalized
 * @property string $kind
 * @property float $confidence
 */
class StockAlias extends Model
{
    public const KIND_TICKER = 'ticker';

    public const KIND_NAME = 'name';

    public const KIND_ALIAS = 'alias';

    /** Confidence per alias kind (the spec scale). */
    public const CONFIDENCE = [
        self::KIND_TICKER => 1.0,
        self::KIND_NAME => 0.95,
        self::KIND_ALIAS => 0.9,
    ];

    /** Derived (suffix-stripped / partial) aliases score lower. */
    public const CONFIDENCE_DERIVED = 0.8;

    protected $fillable = ['stock_id', 'alias', 'normalized', 'kind', 'confidence'];

    protected function casts(): array
    {
        return ['confidence' => 'float'];
    }

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
