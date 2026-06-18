<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One original source of a (possibly merged) news article.
 *
 * @property int $id
 * @property int $news_item_id
 * @property int $news_source_id
 * @property string|null $url
 * @property Carbon|null $published_at
 * @property string $original_hash
 * @property-read NewsSource|null $source
 */
class NewsItemSource extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'news_item_id', 'news_source_id', 'url', 'published_at', 'original_hash',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
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
     * @return BelongsTo<NewsSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'news_source_id');
    }
}
