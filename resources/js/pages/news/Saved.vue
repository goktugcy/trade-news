<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '@/components/tradenews/EmptyState.vue';
import NewsFeed from '@/components/tradenews/NewsFeed.vue';
import type { PaginatedNews } from '@/types';

defineProps<{
    news: PaginatedNews;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'News', href: '/news' }, { title: 'Saved', href: '/news/saved' }] },
});
</script>

<template>
    <Head title="Saved News" />

    <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-lg font-semibold text-foreground">Saved News</h1>

        <EmptyState
            v-if="!news.data.length"
            title="No saved articles yet"
            description="Tap the bookmark on any story to save it here for later."
        >
            <Link href="/news" class="text-sm font-medium text-foreground hover:underline">Browse news →</Link>
        </EmptyState>

        <template v-else>
            <NewsFeed :news="news.data" />

            <!-- Pagination -->
            <div v-if="news.meta.last_page > 1" class="flex items-center justify-between pt-2">
                <Link
                    v-if="news.meta.prev_page_url"
                    :href="news.meta.prev_page_url"
                    preserve-scroll
                    class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border"
                >
                    ← Newer
                </Link>
                <span v-else />
                <span class="text-sm text-muted-foreground">Page {{ news.meta.current_page }} of {{ news.meta.last_page }}</span>
                <Link
                    v-if="news.meta.next_page_url"
                    :href="news.meta.next_page_url"
                    preserve-scroll
                    class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border"
                >
                    Older →
                </Link>
                <span v-else />
            </div>
        </template>
    </div>
</template>
