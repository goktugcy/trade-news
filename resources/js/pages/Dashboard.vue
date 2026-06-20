<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Bell, ListChecks, Newspaper } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import MarketSummaryPanel from '@/components/tradenews/MarketSummaryPanel.vue';
import NewsFeed from '@/components/tradenews/NewsFeed.vue';
import OnboardingWizard from '@/components/tradenews/OnboardingWizard.vue';
import StatCard from '@/components/tradenews/StatCard.vue';
import WatchlistPanel from '@/components/tradenews/WatchlistPanel.vue';
import type { MarketStatusInfo, NewsCardData, NewsSourcePref, SelectOption, StockRow } from '@/types';

defineProps<{
    feed: NewsCardData[];
    watchlist: StockRow[];
    topMovers: { gainers: StockRow[]; losers: StockRow[] };
    marketStatus: MarketStatusInfo[];
    latestAlerts: Array<{ id: number; title: string; status: string; channel: string; sent_at: string | null; created_at: string | null }>;
    onboardingOptions: { sources: NewsSourcePref[]; markets: SelectOption[] };
    stats: { watchlist_count: number; matched_news_today: number };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }] },
});

const page = usePage();
const { t } = useI18n();
const shouldShowOnboarding = computed(() => page.props.onboarding?.should_show === true);
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <OnboardingWizard
            v-if="shouldShowOnboarding"
            :sources="onboardingOptions.sources"
            :markets="onboardingOptions.markets"
        />

        <!-- Stat row -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard :label="t('dashboard.watchlist')" :value="stats.watchlist_count" :icon="ListChecks" :hint="t('dashboard.stocksYouFollow')" />
            <StatCard :label="t('dashboard.matchedNewsToday')" :value="stats.matched_news_today" :icon="Newspaper" :hint="t('dashboard.acrossAllMarkets')" />
            <StatCard :label="t('dashboard.recentAlerts')" :value="latestAlerts.length" :icon="Bell" :hint="t('dashboard.lastDelivered')" />
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
            <!-- Center feed -->
            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <h1 class="text-lg font-semibold text-foreground">{{ t('dashboard.latestMarketNews') }}</h1>
                    <Link href="/news" class="text-sm text-muted-foreground hover:text-foreground">{{ t('dashboard.viewAll') }} →</Link>
                </div>
                <NewsFeed
                    :news="feed"
                    :empty-title="t('dashboard.noMatchedNews')"
                    :empty-description="t('dashboard.noMatchedNewsDescription')"
                />
            </div>

            <!-- Right rail -->
            <aside class="flex flex-col gap-4">
                <WatchlistPanel :items="watchlist" />
                <MarketSummaryPanel :market-status="marketStatus" :movers="topMovers" />

                <section class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                    <header class="flex items-center justify-between border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                        <h2 class="text-sm font-semibold text-foreground">{{ t('dashboard.latestAlerts') }}</h2>
                        <Link href="/alerts" class="text-xs text-muted-foreground hover:text-foreground">{{ t('dashboard.rules') }}</Link>
                    </header>
                    <div v-if="latestAlerts.length === 0" class="px-4 py-6 text-center text-sm text-muted-foreground">
                        {{ t('dashboard.noAlerts') }}
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
