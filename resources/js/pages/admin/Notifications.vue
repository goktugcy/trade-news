<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminNav from '@/components/tradenews/AdminNav.vue';

type Log = {
    id: number;
    user: string | null;
    title: string;
    channel: string;
    status: string;
    error: string | null;
    sent_at: string | null;
    created_at: string | null;
};

defineProps<{
    logs: {
        data: Log[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const badge: Record<string, string> = {
    sent: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    queued: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    failed: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
};

function badgeClass(status: string): string {
    return badge[status] ?? 'bg-muted text-muted-foreground';
}
</script>

<template>
    <Head title="Admin · Notifications" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <div
            v-if="logs.data.length === 0"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 px-6 py-12 text-center dark:border-sidebar-border"
        >
            <p class="text-sm font-medium text-foreground">No notifications yet</p>
            <p class="text-sm text-muted-foreground">Delivery logs will appear here once notifications are sent.</p>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 text-left text-xs uppercase tracking-wide text-muted-foreground dark:border-sidebar-border">
                            <th class="px-4 py-2 font-medium">User</th>
                            <th class="px-4 py-2 font-medium">Title</th>
                            <th class="px-4 py-2 font-medium">Channel</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 font-medium">Sent</th>
                            <th class="px-4 py-2 font-medium">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <template v-for="log in logs.data" :key="log.id">
                            <tr class="hover:bg-accent">
                                <td class="px-4 py-2 text-foreground">{{ log.user ?? '—' }}</td>
                                <td class="max-w-xs truncate px-4 py-2 text-foreground">{{ log.title }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ log.channel }}</td>
                                <td class="px-4 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass(log.status)">
                                        {{ log.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-muted-foreground">{{ log.sent_at ?? '—' }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ log.created_at ?? '—' }}</td>
                            </tr>
                            <tr v-if="log.error" class="border-0">
                                <td colspan="6" class="px-4 pb-2 text-xs text-rose-600 dark:text-rose-400">{{ log.error }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="logs.data.length > 0" class="flex items-center justify-between text-sm text-muted-foreground">
            <span>Page {{ logs.current_page }} of {{ logs.last_page }}</span>
            <div class="flex items-center gap-2">
                <Link
                    v-if="logs.prev_page_url"
                    :href="logs.prev_page_url"
                    preserve-scroll
                    class="rounded-md border border-sidebar-border/70 px-3 py-1.5 font-medium text-foreground transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    Newer
                </Link>
                <Link
                    v-if="logs.next_page_url"
                    :href="logs.next_page_url"
                    preserve-scroll
                    class="rounded-md border border-sidebar-border/70 px-3 py-1.5 font-medium text-foreground transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    Older
                </Link>
            </div>
        </div>
    </div>
</template>
