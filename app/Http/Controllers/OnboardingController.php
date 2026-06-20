<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOnboardingPreferencesRequest;
use App\Models\NewsSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function update(UpdateOnboardingPreferencesRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($validated, $user): void {
            $user->forceFill([
                'locale' => $validated['locale'],
            ])->save();

            $preferredMarkets = collect($validated['preferred_markets'] ?? [])
                ->unique()
                ->values()
                ->all();

            $user->dataPreference()->updateOrCreate([], [
                'preferred_markets' => $preferredMarkets === [] ? null : $preferredMarkets,
                'onboarding_completed_at' => now(),
            ]);

            $sourcePreferences = collect($validated['news_sources'])
                ->keyBy('id')
                ->map(fn (array $source): bool => (bool) $source['enabled']);

            $activeSourceIds = NewsSource::query()
                ->whereIn('id', $sourcePreferences->keys())
                ->where('is_active', true)
                ->pluck('id');

            foreach ($activeSourceIds as $sourceId) {
                if ($sourcePreferences->get($sourceId) === true) {
                    $user->disabledNewsSources()->where('news_source_id', $sourceId)->delete();

                    continue;
                }

                $user->disabledNewsSources()->firstOrCreate([
                    'news_source_id' => $sourceId,
                ]);
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Preferences saved.'),
        ]);

        return back();
    }
}
