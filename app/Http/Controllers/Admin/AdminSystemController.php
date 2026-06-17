<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\SystemJob;
use Illuminate\Http\RedirectResponse;
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
}
