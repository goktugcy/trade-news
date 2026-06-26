<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ProviderType;
use App\Enums\StockIndex;
use App\Http\Controllers\Controller;
use App\Models\ApiProvider;
use App\Models\Notification;
use App\Models\ProviderEvent;
use App\Models\Stock;
use App\Models\StockIndexMembership;
use App\Models\StockPrice;
use App\Models\SyncRun;
use App\Models\SystemJob;
use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin views over jobs, failed jobs, and notification delivery logs.
 */
class AdminSystemController extends Controller
{
    public function jobs(): Response
    {
        $failed = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($j) => [
                'id' => $j->id,
                'uuid' => $j->uuid,
                'queue' => $j->queue,
                'connection' => $j->connection,
                'exception' => mb_substr((string) $j->exception, 0, 300),
                'failed_at' => $j->failed_at,
            ]);

        return Inertia::render('admin/Jobs', [
            'systemJobs' => SystemJob::query()
                ->latest('id')
                ->limit(60)
                ->get()
                ->map(fn (SystemJob $j) => [
                    'id' => $j->id,
                    'name' => $j->name,
                    'status' => $j->status,
                    'duration_ms' => $j->duration_ms,
                    'message' => $j->message,
                    'meta' => $j->meta,
                    'started_at' => $j->started_at?->toDateTimeString(),
                ]),
            'failedJobs' => $failed,
            'pendingCount' => DB::table('jobs')->count(),
        ]);
    }

    public function retryFailed(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Job re-queued.']);

        return back();
    }

    public function flushFailed(): RedirectResponse
    {
        Artisan::call('queue:flush');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Failed jobs flushed.']);

        return back();
    }

    public function notifications(): Response
    {
        $logs = Notification::query()
            ->with(['user:id,name,email', 'newsItem:id,title'])
            ->latest('id')
            ->paginate(25)
            ->through(fn (Notification $n) => [
                'id' => $n->id,
                'user' => $n->user?->name,
                'title' => $n->title,
                'channel' => $n->channel,
                'status' => $n->status,
                'error' => $n->error,
                'sent_at' => $n->sent_at?->toDateTimeString(),
                'created_at' => $n->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('admin/Notifications', [
            'logs' => $logs,
        ]);
    }

    public function providerEvents(): Response
    {
        $events = ProviderEvent::query()
            ->with('provider:id,key,name')
            ->latest('id')
            ->paginate(40)
            ->through(fn (ProviderEvent $e) => [
                'id' => $e->id,
                'provider' => $e->provider->name,
                'from_status' => $e->from_status?->value,
                'to_status' => $e->to_status->value,
                'to_color' => $e->to_status->color(),
                'reason' => $e->reason,
                'created_at' => $e->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('admin/ProviderEvents', [
            'events' => $events,
        ]);
    }

    public function syncLogs(): Response
    {
        $runs = SyncRun::query()
            ->latest('id')
            ->paginate(30)
            ->through(fn (SyncRun $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'provider_key' => $r->provider_key,
                'status' => $r->status,
                'processed' => $r->processed,
                'created_count' => $r->created_count,
                'updated_count' => $r->updated_count,
                'error' => $r->error,
                'detail' => $this->syncRunDetail($r),
                'started_at' => $r->started_at?->toDateTimeString(),
                'finished_at' => $r->finished_at?->toDateTimeString(),
            ]);

        $summary = (new Collection(['nasdaq_list', 'company_profiles', 'manual_import', 'bulk_import']))->mapWithKeys(fn (string $type) => [
            $type => [
                'last_success' => SyncRun::lastOfStatus($type, SyncRun::STATUS_SUCCESS)?->finished_at?->diffForHumans(),
                'last_failure' => SyncRun::lastOfStatus($type, SyncRun::STATUS_FAILED)?->finished_at?->diffForHumans(),
            ],
        ]);

        return Inertia::render('admin/SyncLogs', [
            'runs' => $runs,
            'summary' => $summary,
        ]);
    }

    /**
     * Market-data monitoring: provider usage/health, sync freshness, index sizes.
     */
    public function marketData(): Response
    {
        $providers = ApiProvider::query()
            ->where('type', ProviderType::MarketData->value)
            ->orderBy('priority')
            ->get()
            ->map(fn (ApiProvider $p): array => [
                'key' => $p->key,
                'name' => $p->name,
                'status' => $p->status->value,
                'status_color' => $p->status->color(),
                'is_active' => $p->is_active,
                'markets' => $p->markets ?? [],
                'capabilities' => $p->capabilities ?? [],
                'daily_request_count' => $p->daily_request_count,
                'daily_failure_count' => $p->daily_failure_count,
                'avg_latency_ms' => $p->avg_latency_ms,
                'last_latency_ms' => $p->last_latency_ms,
                'rate_limited' => str_contains((string) $p->last_error, '429'),
                'consecutive_failures' => $p->consecutive_failures,
                'last_error' => $p->last_error,
                'last_checked_at' => $p->last_checked_at?->diffForHumans(),
            ])
            ->all();

        $freshness = (new Collection(['nasdaq_list', 'company_profiles']))
            ->map(function (string $type): array {
                $last = SyncRun::query()->where('type', $type)->latest('id')->first();

                return [
                    'type' => $type,
                    'status' => $last?->status,
                    'finished_at' => $last?->finished_at?->diffForHumans(),
                    'processed' => $last?->processed,
                ];
            })->all();

        $latestPriceAt = StockPrice::query()->max('created_at');

        return Inertia::render('admin/MarketDataMonitoring', [
            'providers' => $providers,
            'freshness' => $freshness,
            'quote_freshness' => $latestPriceAt
                ? Carbon::parse($latestPriceAt)->diffForHumans()
                : null,
            'index_counts' => [
                'nasdaq100' => StockIndexMembership::query()->where('index_key', StockIndex::Nasdaq100->value)->where('is_current', true)->count(),
                'sp500' => StockIndexMembership::query()->where('index_key', StockIndex::Sp500->value)->where('is_current', true)->count(),
                'active_stocks' => Stock::query()->where('is_active', true)->count(),
            ],
        ]);
    }

    /**
     * Human-readable detail for a sync run (e.g. the imported stock symbol or a
     * "no data" note), pulled from its meta payload.
     */
    private function syncRunDetail(SyncRun $run): ?string
    {
        $meta = $run->meta ?? [];

        $parts = array_filter([
            $meta['symbol'] ?? null,
            isset($meta['files']) ? "{$meta['files']} file(s)" : null,
            $meta['note'] ?? null,
            (isset($meta['skipped']) && (int) $meta['skipped'] > 0) ? "{$meta['skipped']} skipped" : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public function systemNotifications(): Response
    {
        $logs = UserNotification::query()
            ->whereIn('category', ['provider', 'sync', 'system'])
            ->with('user:id,name')
            ->latest('id')
            ->paginate(30)
            ->through(fn (UserNotification $n) => [
                'id' => $n->id,
                'user' => $n->user?->name,
                'category' => $n->category->value,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'is_read' => $n->isRead(),
                'created_at' => $n->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('admin/SystemNotifications', [
            'logs' => $logs,
        ]);
    }
}
