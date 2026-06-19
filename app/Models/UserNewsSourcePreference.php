<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a news source the user has DISABLED for their feed (default opt-out).
 *
 * @property int $id
 * @property int $user_id
 * @property int $news_source_id
 */
class UserNewsSourcePreference extends Model
{
    protected $fillable = [
        'user_id', 'news_source_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NewsSource, $this>
     */
    public function newsSource(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class);
    }
}
