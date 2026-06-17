<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $symbol
 * @property string $name
 * @property Market $market
 * @property string|null $exchange
 * @property string $currency
 * @property array<int, string>|null $aliases
 * @property array<int, string>|null $keywords
 * @property bool $is_active
 */
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    protected $fillable = [
        'symbol', 'name', 'market', 'exchange', 'currency',
        'logo_url', 'sector', 'aliases', 'keywords', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'aliases' => 'array',
            'keywords' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Bind stocks by symbol in routes (/stocks/AAPL).
     */
    public function getRouteKeyName(): string
    {
        return 'symbol';
    }

    /**
     * @return HasMany<StockPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(StockPrice::class);
    }

    /**
     * The most recent intraday candle (used for "current price").
     *
     * @return HasOne<StockPrice, $this>
     */
    public function latestPrice(): HasOne
    {
        return $this->hasOne(StockPrice::class)->latestOfMany('price_at');
    }

    /**
     * @return HasMany<NewsStockMatch, $this>
     */
    public function newsMatches(): HasMany
    {
        return $this->hasMany(NewsStockMatch::class);
    }

    /**
     * @return BelongsToMany<NewsItem, $this>
     */
    public function news(): BelongsToMany
    {
        return $this->belongsToMany(NewsItem::class, 'news_stock_matches')
            ->withPivot(['match_type', 'matched_term', 'confidence', 'created_at']);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'watchlists')
            ->withPivot(['alerts_enabled'])
            ->withTimestamps();
    }

    /**
     * All searchable terms for the news matcher: symbol + name + aliases.
     *
     * @return array<int, string>
     */
    public function matchTerms(): array
    {
        return array_values(array_unique(array_filter([
            $this->symbol,
            $this->name,
            ...($this->aliases ?? []),
        ])));
    }

    /** @param  Builder<Stock>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<Stock>  $query */
    public function scopeMarket(Builder $query, Market|string $market): void
    {
        $query->where('market', $market instanceof Market ? $market->value : $market);
    }

    /** @param  Builder<Stock>  $query */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        $query->where(function (Builder $q) use ($term): void {
            $q->where('symbol', 'ILIKE', "%{$term}%")
                ->orWhere('name', 'ILIKE', "%{$term}%");
        });
    }
}
