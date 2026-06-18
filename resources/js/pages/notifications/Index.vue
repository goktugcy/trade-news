<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck, Trash2 } from '@lucide/vue';
import EmptyState from '@/components/tradenews/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { useUserTimezone } from '@/composables/useUserTimezone';
import type { SelectOption } from '@/types';

type Item = {
    id: number;
    category: string;
    category_color: string;
    type: string;
    title: string;
    body: string | null;
    action_url: string | null;
    is_read: boolean;
    created_at: string | null;
};

const props = defineProps<{
    notifications: {
        data: Item[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: { category: string; unread: boolean };
    categories: SelectOption[];
    unreadCount: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Notifications', href: '/notifications' }] },
});

const { relative, dateTime } = useUserTimezone();

function apply(overrides: Record<string, string | boolean | null>) {
    const query: Record<string, string> = {};
    const category = (overrides.category ?? props.filters.category) as string;
    const unread = overrides.unread !== undefined ? overrides.unread : props.filters.unread;
    if (category && category !== 'all') query.category = category;
    if (unread) query.unread = '1';
    router.get('/notifications', query, { preserveState: true, preserveScroll: true, replace: true });
}

function markRead(item: Item) {
    if (!item.is_read) {
        router.patch(`/notifications/${item.id}/read`, {}, { preserveScroll: true });
    }
}

function destroy(item: Item) {
    router.delete(`/notifications/${item.id}`, { preserveScroll: true });
}

function markAllRead() {
    router.post('/notifications/read-all', {}, { preserveScroll: true });
}

const colorClass = (c: string): string =>
    ({
        amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
        slate: 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300',
        violet: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
        emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    })[c] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300';

const tabs = [{ value: 'all', label: 'All' }, ...props.categories.map((c) => ({ value: String(c.value), label: c.label }))];
</script>

<template>
    <Head title="Notifications" />

    <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-lg font-semibold text-foreground">Notifications</h1>
            <Button v-if="unreadCount > 0" variant="outline" size="sm" @click="markAllRead">
                <CheckCheck class="size-4" /> Mark all read ({{ unreadCount }})
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border">
            <div class="inline-flex flex-wrap rounded-lg bg-muted p-0.5">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="rounded-md px-3 py-1 text-sm font-medium transition-colors"
                    :class="filters.category === tab.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                    @click="apply({ category: tab.value })"
                >
                    {{ tab.label }}
                </button>
            </div>
            <label class="ml-auto flex items-center gap-2 text-sm text-muted-foreground">
                <input type="checkbox" class="size-4 accent-primary" :checked="filters.unread" @change="apply({ unread: !filters.unread })" />
                Unread only
            </label>
        </div>

        <EmptyState
            v-if="notifications.data.length === 0"
            :icon="Bell"
            title="No notifications"
            description="Triggered alerts and platform messages will show up here."
        />

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="item in notifications.data"
                :key="item.id"
                class="flex items-start gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
                :class="!item.is_read ? 'border-l-2 border-l-sky-500' : ''"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded px-1.5 py-0.5 text-[10px] font-medium capitalize" :class="colorClass(item.category_color)">{{ item.category }}</span>
                        <component
                            :is="item.action_url ? 'a' : 'span'"
                            :href="item.action_url ?? undefined"
                            class="text-sm font-medium text-foreground"
                            :class="item.action_url ? 'hover:underline' : ''"
                            @click="markRead(item)"
                        >{{ item.title }}</component>
                        <span class="ml-auto text-xs text-muted-foreground" :title="dateTime(item.created_at)">{{ relative(item.created_at) }}</span>
                    </div>
                    <p v-if="item.body" class="mt-1 text-sm text-muted-foreground">{{ item.body }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <button
                        v-if="!item.is_read"
                        type="button"
                        class="rounded-md p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                        title="Mark read"
                        @click="markRead(item)"
                    >
                        <CheckCheck class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        title="Delete"
                        @click="destroy(item)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </li>
        </ul>

        <div v-if="notifications.last_page > 1" class="flex items-center justify-between pt-2">
            <Link
                v-if="notifications.prev_page_url"
                :href="notifications.prev_page_url"
                preserve-scroll
                class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border"
            >← Newer</Link>
            <span v-else />
            <span class="text-sm text-muted-foreground">Page {{ notifications.current_page }} of {{ notifications.last_page }}</span>
            <Link
                v-if="notifications.next_page_url"
                :href="notifications.next_page_url"
                preserve-scroll
                class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border"
            >Older →</Link>
            <span v-else />
        </div>
    </div>
</template>
