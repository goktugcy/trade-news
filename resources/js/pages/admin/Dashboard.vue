<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Activity, AlertTriangle, Bell, Clock, LineChart, Newspaper, Users } from '@lucide/vue';
import AdminNav from '@/components/tradenews/AdminNav.vue';

type Stats = {
    users: number;
    stocks: number;
    active_stocks: number;
    news: number;
    notifications_sent: number;
    failed_jobs: number;
    pending_jobs: number;
};

type Provider = {
    key: string;
    name: string;
    type: string;
    status: string;
    status_color: string;
    is_active: boolean;
    last_latency_ms: number | null;
    last_checked_at: string | null;
};

type Job = {
    id: number;
    name: string;
    status: string;
    duration_ms: number | null;
    message: string | null;
    started_at: string | null;
};

defineProps<{
    stats: Stats;
    providers: Provider[];
    recentJobs: Job[];
    queue: { connection: string; cache: string };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const dotColors: Record<string, string> = {
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
    rose: 'bg-rose-500',
    slate: 'bg-slate-400',
};

const jobBadge: Record<string, string> = {
    success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    running: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    failed: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
};

function dotClass(color: string): string {
    return dotColors[color] ?? 'bg-slate-400';
}

function badgeClass(status: string): string {
    return jobBadge[status] ?? 'bg-muted text-muted-foreground';
}
</script>

<template>
    <Head title="Admin · Overview" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Users</span>
                    <Users class="size-4 text-muted-foreground" />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-foreground">{{ stats.users }}</p>
            </div>

            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Stocks</span>
                    <LineChart class="size-4 text-muted-foreground" />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-foreground">{{ stats.stocks }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ stats.active_stocks }} active</p>
            </div>

            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">News</span>
                    <Newspaper class="size-4 text-muted-foreground" />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-foreground">{{ stats.news }}</p>
            </div>

            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Notifications</span>
                    <Bell class="size-4 text-muted-foreground" />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-foreground">{{ stats.notifications_sent }}</p>
                <p class="mt-1 text-xs text-muted-foreground">sent</p>
            </div>

            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Pending jobs</span>
                    <Clock class="size-4 text-muted-foreground" />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-foreground">{{ stats.pending_jobs }}</p>
            </div>

            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Failed jobs</span>
                    <AlertTriangle class="size-4 text-muted-foreground" />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-foreground">{{ stats.failed_jobs }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- API Providers -->
            <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                <div class="flex items-center gap-2 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                    <Activity class="size-4 text-muted-foreground" />
                    <h2 class="text-sm font-semibold text-foreground">API Providers</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-sidebar-border/70 text-left text-xs uppercase tracking-wide text-muted-foreground dark:border-sidebar-border">
                                <th class="px-4 py-2 font-medium">Name</th>
                                <th class="px-4 py-2 font-medium">Type</th>
                                <th class="px-4 py-2 font-medium">Status</th>
                                <th class="px-4 py-2 text-right font-medium">Latency</th>
                                <th class="px-4 py-2 text-right font-medium">Checked</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            <tr v-for="p in providers" :key="p.key" class="hover:bg-accent">
                                <td class="px-4 py-2 font-medium text-foreground">{{ p.name }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ p.type }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="size-2 rounded-full" :class="dotClass(p.status_color)" />
                                        <span class="text-muted-foreground">{{ p.status }}</span>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">
                                    {{ p.last_latency_ms !== null ? p.last_latency_ms + ' ms' : '—' }}
                                </td>
                                <td class="px-4 py-2 text-right text-muted-foreground">{{ p.last_checked_at ?? '—' }}</td>
                            </tr>
                            <tr v-if="providers.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-muted-foreground">No providers configured.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent jobs -->
            <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                <div class="flex items-center gap-2 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                    <Clock class="size-4 text-muted-foreground" />
                    <h2 class="text-sm font-semibold text-foreground">Recent jobs</h2>
                </div>
                <ul class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <li v-for="job in recentJobs" :key="job.id" class="px-4 py-3 hover:bg-accent">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate font-medium text-foreground">{{ job.name }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass(job.status)">
                                {{ job.status }}
                            </span>
                        </div>
                        <div class="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                            <span class="tabular-nums">{{ job.duration_ms !== null ? job.duration_ms + ' ms' : '—' }}</span>
                            <span v-if="job.started_at">{{ job.started_at }}</span>
                        </div>
                        <p v-if="job.message" class="mt-1 text-xs text-muted-foreground">{{ job.message }}</p>
                    </li>
                    <li v-if="recentJobs.length === 0" class="px-4 py-6 text-center text-sm text-muted-foreground">
                        No recent jobs.
                    </li>
                </ul>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-sidebar-border/70 bg-card px-3 py-1 text-xs text-muted-foreground dark:border-sidebar-border">
                Queue: <span class="font-medium text-foreground">{{ queue.connection }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-sidebar-border/70 bg-card px-3 py-1 text-xs text-muted-foreground dark:border-sidebar-border">
                Cache: <span class="font-medium text-foreground">{{ queue.cache }}</span>
            </span>
        </div>
    </div>
</template>
