<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Bell, BellOff, Star } from '@lucide/vue';
import { ref } from 'vue';
import MarketBadge from '@/components/tradenews/MarketBadge.vue';
import NewsFeed from '@/components/tradenews/NewsFeed.vue';
import PriceChange from '@/components/tradenews/PriceChange.vue';
import StockChart from '@/components/tradenews/StockChart.vue';
import { Button } from '@/components/ui/button';
import { formatPrice } from '@/lib/format';
import type { NewsCardData, SelectOption, StockRow } from '@/types';

const props = defineProps<{
    stock: StockRow;
    news: NewsCardData[];
    timeframes: SelectOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Stocks', href: '/stocks' },
        ],
    },
});

const timeframe = ref<string>('5m');

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

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
        <!-- Header -->
        <div class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 sm:flex-row sm:items-start sm:justify-between dark:border-sidebar-border">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold text-foreground">{{ stock.symbol }}</h1>
                    <MarketBadge :market="stock.market" />
                </div>
                <p class="text-sm text-muted-foreground">{{ stock.name }}</p>
                <p v-if="stock.sector" class="mt-0.5 text-xs text-muted-foreground">{{ stock.sector }} · {{ stock.exchange }}</p>
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
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-foreground">Price chart</h2>
                <div class="inline-flex rounded-lg bg-muted p-0.5">
                    <button
                        v-for="tf in timeframes"
                        :key="tf.value"
                        type="button"
                        class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                        :class="timeframe === tf.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="timeframe = String(tf.value)"
                    >
                        {{ tf.value }}
                    </button>
                </div>
            </div>
            <StockChart :symbol="stock.symbol" :timeframe="timeframe" />
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
