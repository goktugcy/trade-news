<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminNav from '@/components/tradenews/AdminNav.vue';

type Log = {
    id: number;
    user: string | null;
    category: string;
    type: string;
    title: string;
    body: string | null;
    is_read: boolean;
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

const catClass = (c: string): string =>
    ({
        provider: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
        sync: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        system: 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300',
    })[c] ?? 'bg-muted text-muted-foreground';
</script>

<template>
    <Head title="Admin · System Notifications" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <AdminNav />
        <h1 class="text-lg font-semibold text-foreground">System notification center</h1>
        <p class="text-sm text-muted-foreground">Provider status changes, sync failures/recoveries and critical system events sent to admins.</p>

        <ul class="flex flex-col gap-2">
            <li
                v-for="log in logs.data"
                :key="log.id"
                class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
                :class="!log.is_read ? 'border-l-2 border-l-sky-500' : ''"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded px-1.5 py-0.5 text-[10px] font-medium capitalize" :class="catClass(log.category)">{{ log.category }}</span>
                    <span class="text-sm font-medium text-foreground">{{ log.title }}</span>
                    <span class="ml-auto text-xs text-muted-foreground">{{ log.created_at }}</span>
                </div>
                <p v-if="log.body" class="mt-1 text-sm text-muted-foreground">{{ log.body }}</p>
                <p v-if="log.user" class="mt-0.5 text-[11px] text-muted-foreground">→ {{ log.user }}</p>
            </li>
            <li v-if="logs.data.length === 0" class="rounded-xl border border-sidebar-border/70 bg-card px-4 py-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                No system notifications yet.
            </li>
        </ul>

        <div v-if="logs.last_page > 1" class="flex items-center justify-between">
            <Link v-if="logs.prev_page_url" :href="logs.prev_page_url" preserve-scroll class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border">← Newer</Link>
            <span v-else />
            <span class="text-sm text-muted-foreground">Page {{ logs.current_page }} of {{ logs.last_page }}</span>
            <Link v-if="logs.next_page_url" :href="logs.next_page_url" preserve-scroll class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border">Older →</Link>
            <span v-else />
        </div>
    </div>
</template>
