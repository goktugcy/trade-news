<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NewsSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NewsSourcePreferenceController extends Controller
{
    /**
     * Enable / disable a news source for the current user's feed.
     * Default is opt-out, so a preference row exists only while disabled.
     */
    public function update(Request $request, NewsSource $newsSource): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        if ($validated['enabled']) {
            $user->disabledNewsSources()->where('news_source_id', $newsSource->id)->delete();
        } else {
            $user->disabledNewsSources()->firstOrCreate(['news_source_id' => $newsSource->id]);
        }

        return back();
    }
}
