<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Sentiment;
use Database\Factories\NewsItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $source_id
 * @property string $title
 * @property string|null $summary
 * @property string|null $content
 * @property string|null $url
 * @property Carbon|null $published_at
 * @property Market|null $market
 * @property Sentiment|null $sentiment
 * @property float|null $sentiment_score
 * @property int $importance_score
 * @property bool $is_matched
 * @property string $hash
 * @property-read NewsSource|null $source
 * @property-read Collection<int, Stock> $stocks
 */
class NewsItem extends Model
{
    /** @use HasFactory<NewsItemFactory> */
    use HasFactory;

    public const UPDATED_AT = null; // created_at only

    protected $fillable = [
        'source_id', 'title', 'summary', 'content', 'url', 'image_url',
        'published_at', 'market', 'sentiment', 'sentiment_score',
        'importance_score', 'is_matched', 'hash',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'market' => Market::class,
            'sentiment' => Sentiment::class,
            'sentiment_score' => 'float',
            'importance_score' => 'integer',
            'is_matched' => 'boolean',
        ];
    }

    /**
     * Build the dedupe hash for a raw news payload (title + url + source).
     */
    public static function makeHash(string $title, ?string $url, ?string $sourceKey = null): string
    {
        return hash('sha256', mb_strtolower(trim($title)).'|'.trim((string) $url).'|'.(string) $sourceKey);
    }

    /**
     * @return BelongsTo<NewsSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'source_id');
    }

    /**
     * @return HasMany<NewsStockMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(NewsStockMatch::class);
    }

    /**
     * @return BelongsToMany<Stock, $this>
     */
    public function stocks(): BelongsToMany
    {
        return $this->belongsToMany(Stock::class, 'news_stock_matches')
            ->withPivot(['match_type', 'matched_term', 'confidence', 'created_at']);
    }

    /** @param  Builder<NewsItem>  $query */
    public function scopeForMarket(Builder $query, Market|string|null $market): void
    {
        if ($market === null) {
            return;
        }

        $query->where('market', $market instanceof Market ? $market->value : $market);
    }

    /** @param  Builder<NewsItem>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->orderByDesc('published_at');
    }
}
