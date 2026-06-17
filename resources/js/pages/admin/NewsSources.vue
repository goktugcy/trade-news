<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminNav from '@/components/tradenews/AdminNav.vue';

type Source = {
    id: number;
    key: string;
    name: string;
    provider: string | null;
    market: string | null;
    is_active: boolean;
    news_items_count: number;
};

defineProps<{
    sources: Source[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

function toggle(id: number) {
    router.patch('/admin/news-sources/' + id + '/toggle', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · News Sources" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 text-left text-xs uppercase tracking-wide text-muted-foreground dark:border-sidebar-border">
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Key</th>
                            <th class="px-4 py-2 font-medium">Provider</th>
                            <th class="px-4 py-2 font-medium">Market</th>
                            <th class="px-4 py-2 text-right font-medium">Items</th>
                            <th class="px-4 py-2 text-right font-medium">Active</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <tr v-for="source in sources" :key="source.id" class="hover:bg-accent">
                            <td class="px-4 py-2 font-medium text-foreground">{{ source.name }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-muted-foreground">{{ source.key }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ source.provider ?? '—' }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ source.market ?? '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">{{ source.news_items_count }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    type="button"
                                    class="rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors"
                                    :class="source.is_active
                                        ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:hover:bg-emerald-500/25'
                                        : 'bg-muted text-muted-foreground hover:bg-accent'"
                                    @click="toggle(source.id)"
                                >
                                    {{ source.is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="sources.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-muted-foreground">No news sources configured.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
