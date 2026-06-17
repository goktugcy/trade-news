<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiProvider;
use App\Models\NewsItem;
use App\Models\Notification;
use App\Models\Stock;
use App\Models\SystemJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'users' => User::count(),
                'stocks' => Stock::count(),
                'active_stocks' => Stock::where('is_active', true)->count(),
                'news' => NewsItem::count(),
                'notifications_sent' => Notification::where('status', Notification::STATUS_SENT)->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'pending_jobs' => DB::table('jobs')->count(),
            ],
            'providers' => ApiProvider::query()
                ->orderBy('type')
                ->orderBy('priority')
                ->get()
                ->map(fn (ApiProvider $p) => [
                    'key' => $p->key,
                    'name' => $p->name,
                    'type' => $p->type->value,
                    'status' => $p->status->value,
                    'status_color' => $p->status->color(),
                    'is_active' => $p->is_active,
                    'last_latency_ms' => $p->last_latency_ms,
                    'last_checked_at' => $p->last_checked_at?->diffForHumans(),
                ]),
            'recentJobs' => SystemJob::query()
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (SystemJob $j) => [
                    'id' => $j->id,
                    'name' => $j->name,
                    'status' => $j->status,
                    'duration_ms' => $j->duration_ms,
                    'message' => $j->message,
                    'started_at' => $j->started_at?->diffForHumans(),
                ]),
            'queue' => [
                'connection' => config('queue.default'),
                'cache' => config('cache.default'),
            ],
        ]);
    }
}
