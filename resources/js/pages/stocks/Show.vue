<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Bell, BellOff, Star } from '@lucide/vue';
import { ref } from 'vue';
import MarketBadge from '@/components/tradenews/MarketBadge.vue';
import NewsFeed from '@/components/tradenews/NewsFeed.vue';
import PriceChange from '@/components/tradenews/PriceChange.vue';
import StockChart from '@/components/tradenews/StockChart.vue';
import { Button } from '@/components/ui/button';
import { formatNumber, formatPrice } from '@/lib/format';
import type { NewsCardData, SelectOption, StockRow } from '@/types';

const props = defineProps<{
    stock: StockRow;
    news: NewsCardData[];
    timeframes: SelectOption[];
    chartRanges: SelectOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Stocks', href: '/stocks' },
        ],
    },
});

const timeframe = ref<string>('5m');
const range = ref<string>('latest');
const longRangeValues = new Set(['1mo', '3mo', '5mo', '1y', '5y']);
const intradayRangeValues = new Set(['1h', '3h', '24h']);

function selectTimeframe(value: string | number) {
    timeframe.value = String(value);
}

function selectRange(value: string | number) {
    const nextRange = String(value);

    range.value = nextRange;

    if (longRangeValues.has(nextRange)) {
        timeframe.value = '1d';
    }

    if (intradayRangeValues.has(nextRange) && timeframe.value === '1d') {
        timeframe.value = '5m';
    }
}

function addToWatchlist() {
    router.post('/watchlist', { stock_id: props.stock.id }, { preserveScroll: true });
}

function removeFromWatchlist() {
    if (props.stock.watchlist_id) {
        router.delete(`/watchlist/${props.stock.watchlist_id}`, { preserveScroll: true });
    }
}

function toggleAlert() {
    if (props.stock.watchlist_id) {
        router.patch(`/watchlist/${props.stock.watchlist_id}/alert`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`${stock.symbol} · ${stock.name}`" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <!-- Header -->
        <div class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 sm:flex-row sm:items-start sm:justify-between dark:border-sidebar-border">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold text-foreground">{{ stock.symbol }}</h1>
                    <MarketBadge :market="stock.market" />
                </div>
                <p class="text-sm text-muted-foreground">{{ stock.name }}</p>
                <p v-if="stock.sector || stock.industry || stock.exchange" class="mt-0.5 text-xs text-muted-foreground">
                    {{ [stock.sector, stock.industry, stock.exchange].filter(Boolean).join(' · ') }}
                </p>
                <p v-if="stock.market_cap" class="mt-0.5 text-xs text-muted-foreground">
                    Market cap {{ formatNumber(stock.market_cap) }}
                    <a v-if="stock.website" :href="stock.website" target="_blank" rel="noopener" class="ml-1 text-foreground hover:underline">· Website ↗</a>
                </p>
            </div>

            <div class="flex flex-col items-start gap-2 sm:items-end">
                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-bold tabular-nums text-foreground">{{ formatPrice(stock.price, stock.currency) }}</span>
                </div>
                <PriceChange :change="stock.change" :change-percent="stock.change_percent" />

                <div class="mt-1 flex items-center gap-2">
                    <Button v-if="!stock.in_watchlist" size="sm" variant="outline" @click="addToWatchlist">
                        <Star class="size-4" /> Add to watchlist
                    </Button>
                    <template v-else>
                        <Button size="sm" variant="outline" @click="removeFromWatchlist">
                            <Star class="size-4 fill-current text-amber-500" /> Watching
                        </Button>
                        <Button
                            size="sm"
                            :variant="stock.alerts_enabled ? 'default' : 'outline'"
                            @click="toggleAlert"
                        >
                            <component :is="stock.alerts_enabled ? Bell : BellOff" class="size-4" />
                            {{ stock.alerts_enabled ? 'Alerts on' : 'Alerts off' }}
                        </Button>
                    </template>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <div class="mb-3 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <h2 class="text-sm font-semibold text-foreground">Price chart</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex flex-wrap rounded-lg bg-muted p-0.5" aria-label="Chart range">
                        <button
                            v-for="chartRange in chartRanges"
                            :key="chartRange.value"
                            type="button"
                            class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="range === chartRange.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                            @click="selectRange(chartRange.value)"
                        >
                            {{ chartRange.label }}
                        </button>
                    </div>
                    <div class="inline-flex flex-wrap rounded-lg bg-muted p-0.5" aria-label="Chart timeframe">
                        <button
                            v-for="tf in timeframes"
                            :key="tf.value"
                            type="button"
                            class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="timeframe === tf.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                            @click="selectTimeframe(tf.value)"
                        >
                            {{ tf.value }}
                        </button>
                    </div>
                </div>
            </div>
            <StockChart :symbol="stock.symbol" :timeframe="timeframe" :range="range" />
        </div>

        <!-- Related news -->
        <div class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold text-foreground">Related news</h2>
            <NewsFeed
                :news="news"
                empty-title="No related news yet"
                empty-description="News mentioning this company will appear here once matched."
            />
        </div>
    </div>
</template>
