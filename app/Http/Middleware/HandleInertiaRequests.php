<?php

namespace App\Http\Middleware;

use App\Models\UserDataPreference;
use App\Services\MarketData\MarketSummaryService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'dataPreferences' => function () use ($request): array {
                $preference = $request->user()?->dataPreference;

                return [
                    'auto_refresh_seconds' => $preference instanceof UserDataPreference
                        ? $preference->auto_refresh_seconds
                        : UserDataPreference::DEFAULT_AUTO_REFRESH_SECONDS,
                ];
            },
            // Scrolling top-bar ticker — read-only cache (warmed by the scheduler),
            // so it never triggers the heavy ranking scan on a web request.
            'ticker' => fn () => $request->user()
                ? app(MarketSummaryService::class)->cachedTicker()
                : [],
            // Header bell: unread count for the in-app notification inbox.
            'notifications' => fn () => [
                'unread_count' => $request->user()?->userNotifications()->unread()->count() ?? 0,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
