<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminNav from '@/components/tradenews/AdminNav.vue';

type Run = {
    id: number;
    type: string;
    provider_key: string | null;
    status: string;
    processed: number;
    created_count: number;
    updated_count: number;
    error: string | null;
    detail: string | null;
    started_at: string | null;
    finished_at: string | null;
};

type Summary = Record<string, { last_success: string | null; last_failure: string | null }>;

defineProps<{
    runs: {
        data: Run[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    summary: Summary;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const statusClass = (s: string): string =>
    ({
        success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        running: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        failed: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    })[s] ?? 'bg-muted text-muted-foreground';
</script>

<template>
    <Head title="Admin · Sync Logs" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <AdminNav />
        <h1 class="text-lg font-semibold text-foreground">Market synchronization</h1>

        <div class="grid gap-3 sm:grid-cols-2">
            <div v-for="(s, type) in summary" :key="type" class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <h2 class="text-sm font-semibold capitalize text-foreground">{{ String(type).replace('_', ' ') }}</h2>
                <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">Last success: {{ s.last_success ?? 'never' }}</p>
                <p class="text-xs text-rose-600 dark:text-rose-400">Last failure: {{ s.last_failure ?? 'never' }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <table class="w-full text-sm">
                <thead class="border-b border-sidebar-border/70 text-left text-xs uppercase tracking-wide text-muted-foreground dark:border-sidebar-border">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Type</th>
                        <th class="px-4 py-2.5 font-medium">Detail</th>
                        <th class="px-4 py-2.5 font-medium">Status</th>
                        <th class="px-4 py-2.5 text-right font-medium">Processed</th>
                        <th class="px-4 py-2.5 text-right font-medium">New / Upd</th>
                        <th class="px-4 py-2.5 font-medium">Finished</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <tr v-for="r in runs.data" :key="r.id">
                        <td class="px-4 py-2.5 capitalize text-foreground">{{ r.type.replace('_', ' ') }}</td>
                        <td class="px-4 py-2.5 text-muted-foreground">{{ r.detail ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" :class="statusClass(r.status)">{{ r.status }}</span>
                            <span v-if="r.error" class="ml-2 text-xs text-rose-600 dark:text-rose-400">{{ r.error }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ r.processed }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ r.created_count }} / {{ r.updated_count }}</td>
                        <td class="px-4 py-2.5 text-muted-foreground">{{ r.finished_at ?? '—' }}</td>
                    </tr>
                    <tr v-if="runs.data.length === 0">
                        <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">No sync runs yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="runs.last_page > 1" class="flex items-center justify-between">
            <Link v-if="runs.prev_page_url" :href="runs.prev_page_url" preserve-scroll class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border">← Newer</Link>
            <span v-else />
            <span class="text-sm text-muted-foreground">Page {{ runs.current_page }} of {{ runs.last_page }}</span>
            <Link v-if="runs.next_page_url" :href="runs.next_page_url" preserve-scroll class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border">Older →</Link>
            <span v-else />
        </div>
    </div>
</template>
