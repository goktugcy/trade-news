<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NewsItemTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $news_item_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $summary
 * @property Carbon|null $generated_at
 * @property string|null $provider
 * @property-read NewsItem $newsItem
 */
class NewsItemTranslation extends Model
{
    /** @use HasFactory<NewsItemTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'news_item_id',
        'locale',
        'title',
        'summary',
        'generated_at',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NewsItem, $this>
     */
    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}
