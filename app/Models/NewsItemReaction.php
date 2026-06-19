<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single user's like/dislike on a news item.
 *
 * @property int $id
 * @property int $user_id
 * @property int $news_item_id
 * @property int $value
 */
class NewsItemReaction extends Model
{
    public const LIKE = 1;

    public const DISLIKE = -1;

    protected $fillable = [
        'user_id', 'news_item_id', 'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NewsItem, $this>
     */
    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}
