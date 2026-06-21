<script setup lang="ts">
import { Newspaper } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/tradenews/EmptyState.vue';
import NewFeedPill from '@/components/tradenews/NewFeedPill.vue';
import NewsCard from '@/components/tradenews/NewsCard.vue';
import { useLiveNewsFeed } from '@/composables/useLiveNewsFeed';
import { Skeleton } from '@/components/ui/skeleton';
import type { NewsCardData, NewsFeedScope } from '@/types';

const props = withDefaults(
    defineProps<{
        news: NewsCardData[];
        loading?: boolean;
        emptyTitle?: string;
        emptyDescription?: string;
        // When `scope` is set the feed goes live: it polls for new items
        // (revealed via the pill) and merges in-place updates (translation…).
        scope?: NewsFeedScope;
        liveFilters?: Record<string, string | null | undefined>;
    }>(),
    {
        loading: false,
        emptyTitle: undefined,
        emptyDescription: undefined,
        scope: undefined,
        liveFilters: undefined,
    },
);

const { t } = useI18n();

const live = props.scope
    ? useLiveNewsFeed({
          scope: props.scope,
          initial: () => props.news,
          filters: () => props.liveFilters ?? {},
      })
    : null;

const items = computed<NewsCardData[]>(() => (live ? live.items.value : props.news));
const pendingCount = computed<number>(() => (live ? live.pendingCount.value : 0));

function reveal(): void {
    live?.flush();
    if (typeof window !== 'undefined') {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}
</script>

<template>
    <div class="relative flex flex-col gap-3">
        <template v-if="loading">
            <div
                v-for="n in 4"
                :key="n"
                class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            >
                <div class="mb-3 flex gap-2">
                    <Skeleton class="h-4 w-12 rounded-md" />
                    <Skeleton class="h-4 w-16 rounded-md" />
                </div>
                <Skeleton class="h-4 w-3/4 rounded" />
                <Skeleton class="mt-2 h-3 w-full rounded" />
                <Skeleton class="mt-1.5 h-3 w-2/3 rounded" />
            </div>
        </template>

        <template v-else-if="items.length === 0">
            <EmptyState :title="emptyTitle ?? t('news.noNews')" :description="emptyDescription ?? t('news.noNewsDescription')" :icon="Newspaper" />
        </template>

        <template v-else>
            <NewFeedPill :count="pendingCount" @reveal="reveal" />
            <TransitionGroup name="news" tag="div" class="flex flex-col gap-3">
                <NewsCard v-for="item in items" :key="item.id" :news="item" />
            </TransitionGroup>
        </template>
    </div>
</template>

<style scoped>
.news-enter-active {
    transition:
        opacity 0.35s ease,
        transform 0.35s ease;
}

.news-enter-from {
    opacity: 0;
    transform: translateY(-12px);
}

.news-move {
    transition: transform 0.35s ease;
}
</style>
