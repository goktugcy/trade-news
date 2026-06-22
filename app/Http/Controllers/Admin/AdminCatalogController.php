<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Market;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Models\ApiProvider;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\Providers\ApiProviderRegistry;
use App\Services\Providers\ProviderHealthService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin management for the supporting catalog entities: news sources,
 * API providers, and users.
 */
class AdminCatalogController extends Controller
{
    // ---------------- News sources ----------------

    public function newsSources(): Response
    {
        $configuredFeeds = $this->configuredRssFeeds();

        return Inertia::render('admin/NewsSources', [
            'sources' => NewsSource::query()
                ->withCount('newsItems')
                ->orderBy('name')
                ->get()
                ->map(fn (NewsSource $s) => [
                    'id' => $s->id,
                    'key' => $s->key,
                    'name' => $s->name,
                    'provider' => $s->provider,
                    'market' => $s->market,
                    'language' => $s->language,
                    'feed_url' => $s->feed_url ?: ($configuredFeeds[$s->key]['url'] ?? null),
                    'homepage_url' => $s->homepage_url ?: ($configuredFeeds[$s->key]['homepage_url'] ?? null),
                    'is_active' => $s->is_active,
                    'is_rss' => $s->provider === 'rss',
                    'news_items_count' => $s->news_items_count,
                ]),
            'marketOptions' => [
                ['value' => null, 'label' => 'Global'],
                ['value' => Market::BIST->value, 'label' => Market::BIST->value],
                ['value' => Market::NASDAQ->value, 'label' => Market::NASDAQ->value],
            ],
        ]);
    }

    public function toggleNewsSource(NewsSource $newsSource): RedirectResponse
    {
        $newsSource->update(['is_active' => ! $newsSource->is_active]);

        return back();
    }

    public function storeNewsSource(Request $request): RedirectResponse
    {
        $validated = $this->validateRssNewsSource($request, null);

        NewsSource::query()->create($validated + ['provider' => 'rss']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'RSS source created.']);

        return back();
    }

    public function updateNewsSource(Request $request, NewsSource $newsSource): RedirectResponse
    {
        abort_unless($newsSource->provider === 'rss', 403);

        $validated = $this->validateRssNewsSource($request, $newsSource);

        $newsSource->update($validated + ['provider' => 'rss']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'RSS source updated.']);

        return back();
    }

    public function destroyNewsSource(NewsSource $newsSource): RedirectResponse
    {
        abort_unless($newsSource->provider === 'rss', 403);

        $newsSource->update(['is_active' => false]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'RSS source deactivated.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRssNewsSource(Request $request, ?NewsSource $existing): array
    {
        $validated = $request->validate([
            'key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]*$/i',
                Rule::unique('news_sources', 'key')->ignore($existing?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'feed_url' => ['required', 'url', 'max:1024'],
            'homepage_url' => ['nullable', 'url', 'max:255'],
            'market' => ['nullable', Rule::in([Market::BIST->value, Market::NASDAQ->value])],
            'language' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]);

        $validated['market'] = $validated['market'] ?? null;

        if ($validated['market'] === '') {
            $validated['market'] = null;
        }

        $validated['language'] = ($validated['language'] ?? null) ?: null;

        $validated['is_active'] = $request->has('is_active')
            ? $request->boolean('is_active')
            : ($existing?->is_active ?? true);

        return $validated;
    }

    // ---------------- API providers ----------------

    public function apiProviders(): Response
    {
        return Inertia::render('admin/ApiProviders', [
            'providers' => ApiProvider::query()
                ->where('type', '!=', ProviderType::Ai->value)
                ->orderBy('type')
                ->orderBy('priority')
                ->get()
                ->map(fn (ApiProvider $p) => [
                    'id' => $p->id,
                    'key' => $p->key,
                    'name' => $p->name,
                    'type' => $p->type->value,
                    'markets' => $p->markets ?? [],
                    'capabilities' => $p->capabilities ?? [],
                    'status' => $p->status->value,
                    'status_color' => $p->status->color(),
                    'is_active' => $p->is_active,
                    'auto_sync_stocks' => $p->auto_sync_stocks,
                    'auto_recovery' => $p->auto_recovery,
                    'api_key_configured' => trim((string) $p->api_key) !== '',
                    'consecutive_failures' => $p->consecutive_failures,
                    'priority' => $p->priority,
                    'refresh_interval_minutes' => $p->refresh_interval_minutes,
                    'fetch_limit' => $p->fetch_limit,
                    'base_url' => $p->base_url,
                    'last_latency_ms' => $p->last_latency_ms,
                    'avg_latency_ms' => $p->avg_latency_ms,
                    'daily_request_count' => $p->daily_request_count,
                    'daily_failure_count' => $p->daily_failure_count,
                    'rate_limited' => str_contains((string) $p->last_error, '429'),
                    'last_error' => $p->last_error,
                    'last_checked_at' => $p->last_checked_at?->diffForHumans(),
                    'last_fetched_at' => $p->last_fetched_at?->diffForHumans(),
                ]),
            'statuses' => ProviderStatus::options(),
            'types' => array_map(fn (ProviderType $t) => ['value' => $t->value, 'label' => $t->label()], $this->catalogProviderTypes()),
            'marketOptions' => array_map(fn (Market $m) => ['value' => $m->value, 'label' => $m->label()], Market::cases()),
            'capabilityOptions' => ['quotes', 'candles', 'news', 'profiles', 'list', 'index_constituents'],
            'synthetic_counts' => [
                'prices' => $this->syntheticPriceCount(),
                'news' => $this->syntheticNewsCount(),
            ],
        ]);
    }

    public function storeApiProvider(Request $request): RedirectResponse
    {
        $validated = $this->validateProvider($request, null);

        ApiProvider::create($validated + ['status' => ProviderStatus::Unknown->value]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Provider created.']);

        return back();
    }

    public function updateApiProvider(Request $request, ApiProvider $apiProvider, ProviderHealthService $health): RedirectResponse
    {
        $validated = $this->validateProvider($request, $apiProvider);

        // Route enable/disable through the state machine so it logs an event.
        if (array_key_exists('is_active', $validated) && (bool) $validated['is_active'] !== $apiProvider->is_active) {
            $health->setDisabled($apiProvider, ! $validated['is_active']);
        }
        unset($validated['is_active']);

        $apiProvider->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Provider updated.']);

        return back();
    }

    public function destroyApiProvider(ApiProvider $apiProvider): RedirectResponse
    {
        $apiProvider->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Provider deleted.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProvider(Request $request, ?ApiProvider $existing): array
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64', Rule::unique('api_providers', 'key')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_map(fn (ProviderType $type): string => $type->value, $this->catalogProviderTypes()))],
            'markets' => ['nullable', 'array'],
            'markets.*' => [Rule::in(array_column(Market::cases(), 'value'))],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', 'max:32'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:8192'],
            'clear_api_key' => ['boolean'],
            'is_active' => ['boolean'],
            'auto_sync_stocks' => ['boolean'],
            'auto_recovery' => ['boolean'],
            'priority' => ['integer', 'min:1', 'max:999'],
            'refresh_interval_minutes' => ['integer', 'min:1', 'max:1440'],
            'fetch_limit' => ['integer', 'min:1', 'max:5000'],
        ]);

        $validated['markets'] = array_values($validated['markets'] ?? []);
        $validated['capabilities'] = array_values($validated['capabilities'] ?? []);
        $validated['base_url'] = isset($validated['base_url']) && trim((string) $validated['base_url']) !== ''
            ? trim((string) $validated['base_url'])
            : null;

        if ($request->boolean('clear_api_key')) {
            $validated['api_key'] = null;
        } elseif (array_key_exists('api_key', $validated)) {
            $apiKey = trim((string) $validated['api_key']);

            if ($apiKey === '') {
                unset($validated['api_key']);
            } else {
                $validated['api_key'] = $apiKey;
            }
        }

        unset($validated['clear_api_key']);

        return $validated;
    }

    public function purgeSyntheticData(): RedirectResponse
    {
        $prices = StockPrice::query()
            ->where(function (Builder $query): void {
                $query->where('source_kind', StockPrice::SOURCE_SYNTHETIC)
                    ->orWhereIn('provider_key', ApiProviderRegistry::syntheticKeys());
            })
            ->delete();

        $news = NewsItem::query()
            ->whereHas('source', function (Builder $query): void {
                $query->whereIn('provider', ApiProviderRegistry::syntheticKeys())
                    ->orWhereIn('key', ApiProviderRegistry::syntheticKeys());
            })
            ->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Synthetic data purged ({$prices} prices, {$news} news).",
        ]);

        return back();
    }

    // ---------------- Users ----------------

    public function users(Request $request): Response
    {
        $search = trim($request->string('q')->toString());

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'ILIKE', "%{$search}%")->orWhere('email', 'ILIKE', "%{$search}%"))
            ->withCount(['watchlist', 'notificationRules'])
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_admin' => $u->is_admin,
                'watchlist_count' => $u->watchlist_count,
                'rules_count' => $u->notification_rules_count,
                'created_at' => $u->created_at?->toDateString(),
            ]);

        return Inertia::render('admin/Users', [
            'users' => $users,
            'filters' => ['q' => $search ?: null],
        ]);
    }

    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        // Don't let an admin demote themselves and get locked out.
        if ($user->id === $request->user()->id) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'You cannot change your own admin status.']);

            return back();
        }

        $user->update(['is_admin' => ! $user->is_admin]);

        return back();
    }

    private function syntheticPriceCount(): int
    {
        return StockPrice::query()
            ->where(function (Builder $query): void {
                $query->where('source_kind', StockPrice::SOURCE_SYNTHETIC)
                    ->orWhereIn('provider_key', ApiProviderRegistry::syntheticKeys());
            })
            ->count();
    }

    private function syntheticNewsCount(): int
    {
        return NewsItem::query()
            ->whereHas('source', function (Builder $query): void {
                $query->whereIn('provider', ApiProviderRegistry::syntheticKeys())
                    ->orWhereIn('key', ApiProviderRegistry::syntheticKeys());
            })
            ->count();
    }

    /**
     * @return array<int, ProviderType>
     */
    private function catalogProviderTypes(): array
    {
        return array_values(array_filter(
            ProviderType::cases(),
            fn (ProviderType $type): bool => $type !== ProviderType::Ai,
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredRssFeeds(): array
    {
        /** @var array<int, array<string, mixed>> $feeds */
        $feeds = (array) config('tradenews.news.providers.rss.feeds', []);

        return collect($feeds)
            ->filter(fn (array $feed): bool => isset($feed['key']) && is_string($feed['key']))
            ->keyBy(fn (array $feed): string => (string) $feed['key'])
            ->all();
    }
}
