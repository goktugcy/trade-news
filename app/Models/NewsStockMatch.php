<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $news_item_id
 * @property int $stock_id
 * @property string $match_type
 * @property string $matched_term
 * @property float $confidence
 */
class NewsStockMatch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'news_item_id', 'stock_id', 'match_type', 'matched_term', 'confidence',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
        ];
    }

    /**
     * @return BelongsTo<NewsItem, $this>
     */
    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
