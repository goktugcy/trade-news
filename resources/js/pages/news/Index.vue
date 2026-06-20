<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search, SlidersHorizontal } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/tradenews/EmptyState.vue';
import NewsFeed from '@/components/tradenews/NewsFeed.vue';
import NewsSourcePicker from '@/components/tradenews/NewsSourcePicker.vue';
import { Input } from '@/components/ui/input';
import type { NewsSourcePref, PaginatedNews, SelectOption } from '@/types';

const props = defineProps<{
    news: PaginatedNews;
    filters: { market: string; sentiment: string | null; q: string | null };
    options: { markets: SelectOption[]; sentiments: Array<{ value: string; label: string; color: string }> };
    scope: 'all' | 'watchlist' | 'saved';
    watchlistEmpty?: boolean;
    sources?: NewsSourcePref[];
}>();

const showSources = ref(false);
const { t } = useI18n();

defineOptions({
    layout: { breadcrumbs: [{ title: 'News', href: '/news' }] },
});

const baseUrl = () => (props.scope === 'watchlist' ? '/news/watchlist' : '/news');
const search = ref(props.filters.q ?? '');

function apply(overrides: Record<string, string | null>) {
    const query: Record<string, string> = {};
    const market = overrides.market ?? props.filters.market;
    const sentiment = overrides.sentiment !== undefined ? overrides.sentiment : props.filters.sentiment;
    const q = overrides.q !== undefined ? overrides.q : search.value;

    if (market && market !== 'ALL') query.market = market;
    if (sentiment) query.sentiment = sentiment;
    if (q) query.q = q;

    router.get(baseUrl(), query, { preserveState: true, preserveScroll: true, replace: true });
}

let debounce: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => apply({ q: value || null }), 350);
});

const marketTabs = computed(() => [
    { value: 'ALL', label: t('common.all') },
    { value: 'BIST', label: 'BIST' },
    { value: 'NASDAQ', label: 'NASDAQ' },
]);
</script>

<template>
    <Head :title="scope === 'watchlist' ? t('news.watchlistNews') : t('news.title')" />

    <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
        <div class="flex flex-col gap-3">
            <h1 class="text-lg font-semibold text-foreground">
                {{ scope === 'watchlist' ? t('news.watchlistNews') : t('news.allMarketNews') }}
            </h1>

            <!-- Filter bar -->
            <div class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-lg bg-muted p-0.5">
                        <button
                            v-for="tab in marketTabs"
                            :key="tab.value"
                            type="button"
                            class="rounded-md px-3 py-1 text-sm font-medium transition-colors"
                            :class="filters.market === tab.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                            @click="apply({ market: tab.value })"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <button
                        v-if="sources"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium transition-colors"
                        :class="showSources ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/60'"
                        @click="showSources = !showSources"
                    >
                        <SlidersHorizontal class="size-3.5" /> {{ t('common.sources') }}
                    </button>

                    <div class="ml-auto flex items-center gap-1.5">
                        <button
                            type="button"
                            class="rounded-md px-2 py-1 text-xs font-medium transition-colors"
                            :class="!filters.sentiment ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/60'"
                            @click="apply({ sentiment: null })"
                        >
                            {{ t('common.any') }}
                        </button>
                        <button
                            v-for="s in options.sentiments"
                            :key="s.value"
                            type="button"
                            class="rounded-md px-2 py-1 text-xs font-medium capitalize transition-colors"
                            :class="filters.sentiment === s.value ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/60'"
                            @click="apply({ sentiment: s.value })"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                </div>

                <div class="relative">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" :placeholder="t('news.searchPlaceholder')" class="pl-9" />
                </div>

                <NewsSourcePicker v-if="sources && showSources" :sources="sources" />
            </div>
        </div>

        <EmptyState
            v-if="scope === 'watchlist' && watchlistEmpty"
            :title="t('news.watchlistEmpty')"
            :description="t('news.watchlistEmptyDescription')"
        >
            <Link href="/stocks" class="text-sm font-medium text-foreground hover:underline">{{ t('news.browseStocks') }} →</Link>
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
                    ← {{ t('news.newer') }}
                </Link>
                <span v-else />
                <span class="text-sm text-muted-foreground">{{ t('common.page', { current: news.meta.current_page, last: news.meta.last_page }) }}</span>
                <Link
                    v-if="news.meta.next_page_url"
                    :href="news.meta.next_page_url"
                    preserve-scroll
                    class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm hover:bg-accent dark:border-sidebar-border"
                >
                    {{ t('news.older') }} →
                </Link>
                <span v-else />
            </div>
        </template>
    </div>
</template>
