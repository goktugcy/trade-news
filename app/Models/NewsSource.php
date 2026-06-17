<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NewsSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $provider
 * @property string|null $market
 * @property bool $is_active
 */
class NewsSource extends Model
{
    /** @use HasFactory<NewsSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'provider', 'market', 'homepage_url', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<NewsItem, $this>
     */
    public function newsItems(): HasMany
    {
        return $this->hasMany(NewsItem::class, 'source_id');
    }
}
