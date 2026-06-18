<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminNav from '@/components/tradenews/AdminNav.vue';

type Event = {
    id: number;
    provider: string | null;
    from_status: string | null;
    to_status: string;
    to_color: string;
    reason: string | null;
    created_at: string | null;
};

defineProps<{
    events: {
        data: Event[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const textColors: Record<string, string> = {
    emerald: 'text-emerald-600 dark:text-emerald-400',
    amber: 'text-amber-600 dark:text-amber-400',
    rose: 'text-rose-600 dark:text-rose-400',
    slate: 'text-muted-foreground',
};
const toClass = (c: string): string => textColors[c] ?? 'text-muted-foreground';
</script>

<template>
    <Head title="Admin · Provider Events" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <AdminNav />
        <h1 class="text-lg font-semibold text-foreground">Provider status history</h1>

        <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <table class="w-full text-sm">
                <thead class="border-b border-sidebar-border/70 text-left text-xs uppercase tracking-wide text-muted-foreground dark:border-sidebar-border">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Provider</th>
                        <th class="px-4 py-2.5 font-medium">Transition</th>
                        <th class="px-4 py-2.5 font-medium">Reason</th>
                        <th class="px-4 py-2.5 font-medium">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <tr v-for="e in events.data" :key="e.id">
                        <td class="px-4 py-2.5 font-medium text-foreground">{{ e.provider }}</td>
                        <td class="px-4 py-2.5">
                            <span class="text-muted-foreground">{{ e.from_status ?? '—' }}</span>
                            <span class="mx-1">→</span>
                            <span class="font-medium capitalize" :class="toClass(e.to_color)">{{ e.to_status }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-muted-foreground">{{ e.reason }}</td>
                        <td class="px-4 py-2.5 text-muted-foreground">{{ e.created_at }}</td>
                    </tr>
                    <tr v-if="events.data.length === 0">
                        <td colspan="4" class="px-4 py-10 text-center text-muted-foreground">No provider events recorded yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="events.last_page > 1" class="flex items-center justify-between">
            <Link v-if="events.prev_page_url" :href="events.prev_page_url" preserve-scroll class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border">← Newer</Link>
            <span v-else />
            <span class="text-sm text-muted-foreground">Page {{ events.current_page }} of {{ events.last_page }}</span>
            <Link v-if="events.next_page_url" :href="events.next_page_url" preserve-scroll class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border">Older →</Link>
            <span v-else />
        </div>
    </div>
</template>
