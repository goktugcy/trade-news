<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Bell, ListChecks, Newspaper } from '@lucide/vue';
import MarketSummaryPanel from '@/components/tradenews/MarketSummaryPanel.vue';
import NewsFeed from '@/components/tradenews/NewsFeed.vue';
import StatCard from '@/components/tradenews/StatCard.vue';
import WatchlistPanel from '@/components/tradenews/WatchlistPanel.vue';
import type { MarketStatusInfo, NewsCardData, StockRow } from '@/types';

defineProps<{
    feed: NewsCardData[];
    watchlist: StockRow[];
    topMovers: { gainers: StockRow[]; losers: StockRow[] };
    marketStatus: MarketStatusInfo[];
    latestAlerts: Array<{ id: number; title: string; status: string; channel: string; sent_at: string | null; created_at: string | null }>;
    stats: { watchlist_count: number; matched_news_today: number };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }] },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <!-- Stat row -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard label="Watchlist" :value="stats.watchlist_count" :icon="ListChecks" hint="Stocks you follow" />
            <StatCard label="Matched news today" :value="stats.matched_news_today" :icon="Newspaper" hint="Across all markets" />
            <StatCard label="Recent alerts" :value="latestAlerts.length" :icon="Bell" hint="Last delivered" />
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
            <!-- Center feed -->
            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <h1 class="text-lg font-semibold text-foreground">Latest Market News</h1>
                    <Link href="/news" class="text-sm text-muted-foreground hover:text-foreground">View all →</Link>
                </div>
                <NewsFeed
                    :news="feed"
                    empty-title="No matched news yet"
                    empty-description="Run the news fetcher (php artisan tradenews:fetch-news) or seed demo data."
                />
            </div>

            <!-- Right rail -->
            <aside class="flex flex-col gap-4">
                <WatchlistPanel :items="watchlist" />
                <MarketSummaryPanel :market-status="marketStatus" :movers="topMovers" />

                <section class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                    <header class="flex items-center justify-between border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                        <h2 class="text-sm font-semibold text-foreground">Latest Alerts</h2>
                        <Link href="/alerts" class="text-xs text-muted-foreground hover:text-foreground">Rules</Link>
                    </header>
                    <div v-if="latestAlerts.length === 0" class="px-4 py-6 text-center text-sm text-muted-foreground">
                        No alerts delivered yet.
                    </div>
                    <ul v-else class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <li v-for="alert in latestAlerts" :key="alert.id" class="px-4 py-2.5">
                            <p class="line-clamp-1 text-sm text-foreground">{{ alert.title }}</p>
                            <div class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                <span
                                    class="rounded px-1 py-0.5 text-[10px] font-medium"
                                    :class="alert.status === 'sent' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'"
                                >{{ alert.status }}</span>
                                <span>{{ alert.sent_at ?? alert.created_at }}</span>
                            </div>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</template>
