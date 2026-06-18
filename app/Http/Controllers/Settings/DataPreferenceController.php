<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\UserDataPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DataPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $preference = $request->user()
            ->dataPreference()
            ->firstOrCreate([]);

        return Inertia::render('settings/Data', [
            'preference' => [
                'auto_refresh_seconds' => $preference->auto_refresh_seconds,
            ],
            'autoRefreshOptions' => $this->autoRefreshOptions(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'auto_refresh_seconds' => [
                'required',
                'integer',
                Rule::in(UserDataPreference::ALLOWED_AUTO_REFRESH_SECONDS),
            ],
        ]);

        $request->user()
            ->dataPreference()
            ->updateOrCreate([], [
                'auto_refresh_seconds' => $validated['auto_refresh_seconds'],
            ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Data preferences updated.']);

        return back();
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function autoRefreshOptions(): array
    {
        return array_map(fn (int $seconds): array => [
            'value' => $seconds,
            'label' => $seconds === 0 ? 'Off' : "{$seconds}s",
        ], UserDataPreference::ALLOWED_AUTO_REFRESH_SECONDS);
    }
}
