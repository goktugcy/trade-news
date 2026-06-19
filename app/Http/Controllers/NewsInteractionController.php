<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NewsItem;
use App\Models\NewsItemReaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NewsInteractionController extends Controller
{
    /**
     * Like / dislike a news item. Re-clicking the same value clears it.
     */
    public function react(Request $request, NewsItem $newsItem): RedirectResponse
    {
        $validated = $request->validate([
            'value' => ['required', 'integer', 'in:'.NewsItemReaction::LIKE.','.NewsItemReaction::DISLIKE],
        ]);

        $value = (int) $validated['value'];
        $user = $request->user();

        $existing = $user->newsReactions()->where('news_item_id', $newsItem->id)->first();

        if ($existing && $existing->value === $value) {
            $existing->delete();

            return back();
        }

        $user->newsReactions()->updateOrCreate(
            ['news_item_id' => $newsItem->id],
            ['value' => $value],
        );

        return back();
    }

    /**
     * Save / bookmark a news item.
     */
    public function save(Request $request, NewsItem $newsItem): RedirectResponse
    {
        $request->user()->savedNews()->firstOrCreate(['news_item_id' => $newsItem->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved to your news.']);

        return back();
    }

    /**
     * Remove a news item from the user's saved list.
     */
    public function unsave(Request $request, NewsItem $newsItem): RedirectResponse
    {
        $request->user()->savedNews()->where('news_item_id', $newsItem->id)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Removed from saved.']);

        return back();
    }
}
