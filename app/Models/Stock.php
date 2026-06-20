<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Services\Providers\ApiProviderRegistry;
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
        'logo_url', 'sector', 'industry', 'market_cap', 'website', 'description',
        'company_profile', 'profile_synced_at', 'aliases', 'keywords', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'aliases' => 'array',
            'keywords' => 'array',
            'company_profile' => 'array',
            'market_cap' => 'float',
            'profile_synced_at' => 'datetime',
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
     * Has this stock had a price stored within the last $minutes? Used to skip
     * re-fetching symbols that are already fresh.
     */
    public function pricedWithin(int $minutes): bool
    {
        return $this->prices()
            ->where('created_at', '>=', now()->subMinutes(max(1, $minutes)))
            ->exists();
    }

    /**
     * Only stocks WITHOUT a price stored in the last $minutes (least-fresh).
     *
     * @param  Builder<Stock>  $query
     */
    public function scopeStale(Builder $query, int $minutes): void
    {
        $query->whereDoesntHave('prices', fn (Builder $q) => $q->where('created_at', '>=', now()->subMinutes(max(1, $minutes))));
    }

    /**
     * @return HasMany<StockAiAnalysis, $this>
     */
    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(StockAiAnalysis::class);
    }

    /**
     * The most recent AI analysis for this stock.
     *
     * @return HasOne<StockAiAnalysis, $this>
     */
    public function latestAiAnalysis(): HasOne
    {
        return $this->hasOne(StockAiAnalysis::class)->latestOfMany('generated_at');
    }

    /**
     * The most recent intraday candle (used for "current price").
     *
     * @return HasOne<StockPrice, $this>
     */
    public function latestPrice(): HasOne
    {
        $hideSynthetic = app(ApiProviderRegistry::class)->shouldHideSyntheticMarketData();

        return $this->hasOne(StockPrice::class)
            ->withoutSyntheticWhenApiActive($hideSynthetic)
            ->latestOfMany('price_at');
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
