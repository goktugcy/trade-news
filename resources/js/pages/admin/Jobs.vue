<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RotateCcw, Trash2 } from '@lucide/vue';
import AdminNav from '@/components/tradenews/AdminNav.vue';
import { Button } from '@/components/ui/button';

type SystemJob = {
    id: number;
    name: string;
    status: string;
    duration_ms: number | null;
    message: string | null;
    meta: Record<string, unknown> | null;
    started_at: string | null;
};

type FailedJob = {
    id: number;
    uuid: string;
    queue: string;
    connection: string;
    exception: string;
    failed_at: string | null;
};

defineProps<{
    systemJobs: SystemJob[];
    failedJobs: FailedJob[];
    pendingCount: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const badge: Record<string, string> = {
    success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    running: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    failed: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
};

function badgeClass(status: string): string {
    return badge[status] ?? 'bg-muted text-muted-foreground';
}

function flushAll() {
    router.delete('/admin/jobs/failed', { preserveScroll: true });
}

function retry(uuid: string) {
    router.post('/admin/jobs/' + uuid + '/retry', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Jobs & Health" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <!-- Scheduled job runs -->
        <section class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div class="flex items-center justify-between gap-3 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                <h2 class="text-sm font-semibold text-foreground">Scheduled job runs</h2>
                <span class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                    Pending in queue: <span class="tabular-nums">{{ pendingCount }}</span>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 text-left text-xs uppercase tracking-wide text-muted-foreground dark:border-sidebar-border">
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 text-right font-medium">Duration</th>
                            <th class="px-4 py-2 font-medium">Started</th>
                            <th class="px-4 py-2 font-medium">Message</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <tr v-for="job in systemJobs" :key="job.id" class="hover:bg-accent">
                            <td class="px-4 py-2 font-medium text-foreground">{{ job.name }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass(job.status)">
                                    {{ job.status }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">
                                {{ job.duration_ms !== null ? job.duration_ms + ' ms' : '—' }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">{{ job.started_at ?? '—' }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ job.message ?? '—' }}</td>
                        </tr>
                        <tr v-if="systemJobs.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-muted-foreground">No job runs recorded.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Failed jobs -->
        <section class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div class="flex items-center justify-between gap-3 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                <h2 class="text-sm font-semibold text-foreground">Failed jobs</h2>
                <Button v-if="failedJobs.length > 0" variant="destructive" size="sm" @click="flushAll">
                    <Trash2 class="size-4" />
                    Flush all
                </Button>
            </div>

            <ul v-if="failedJobs.length > 0" class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                <li v-for="job in failedJobs" :key="job.id" class="px-4 py-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <p class="truncate font-mono text-xs text-foreground">{{ job.uuid }}</p>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span>{{ job.connection }} / {{ job.queue }}</span>
                                <span v-if="job.failed_at">· {{ job.failed_at }}</span>
                            </div>
                        </div>
                        <Button variant="outline" size="sm" @click="retry(job.uuid)">
                            <RotateCcw class="size-4" />
                            Retry
                        </Button>
                    </div>
                    <pre class="mt-2 max-h-48 overflow-auto rounded-md bg-muted p-3 text-xs whitespace-pre-wrap break-all text-rose-600 dark:text-rose-400">{{ job.exception }}</pre>
                </li>
            </ul>

            <div v-else class="px-4 py-8 text-center text-sm text-muted-foreground">
                No failed jobs. Everything is running smoothly.
            </div>
        </section>
    </div>
</template>
