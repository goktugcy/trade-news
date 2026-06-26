<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, Star } from '@lucide/vue';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MarketBadge from '@/components/tradenews/MarketBadge.vue';
import PriceChange from '@/components/tradenews/PriceChange.vue';
import { Input } from '@/components/ui/input';
import { formatPrice } from '@/lib/format';
import type { SelectOption, StockRow } from '@/types';

const props = defineProps<{
    stocks: StockRow[];
    filters: { market: string; q: string | null };
    options: { markets: SelectOption[] };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Stocks', href: '/stocks' }] },
});

const search = ref(props.filters.q ?? '');
const { t } = useI18n();

function apply(overrides: Record<string, string | null>) {
    const query: Record<string, string> = {};
    const market = overrides.market ?? props.filters.market;
    const q = overrides.q !== undefined ? overrides.q : search.value;

    if (market && market !== 'ALL') {
        query.market = market;
    }

    if (q) {
        query.q = q;
    }

    router.get('/stocks', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let debounce: ReturnType<typeof setTimeout> | undefined;
watch(search, (v) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => apply({ q: v || null }), 300);
});

function addToWatchlist(stock: StockRow) {
    router.post('/watchlist', { stock_id: stock.id }, { preserveScroll: true });
}

const tabs = [
    { value: 'ALL', label: t('common.all') },
    { value: 'NASDAQ', label: 'NASDAQ' },
];
</script>

<template>
    <Head :title="t('stocks.title')" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-lg font-semibold text-foreground">
            {{ t('stocks.title') }}
        </h1>

        <div
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-3 sm:flex-row sm:items-center dark:border-sidebar-border"
        >
            <div class="inline-flex rounded-lg bg-muted p-0.5">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="rounded-md px-3 py-1 text-sm font-medium transition-colors"
                    :class="
                        filters.market === tab.value
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="apply({ market: tab.value })"
                >
                    {{ tab.label }}
                </button>
            </div>
            <div class="relative flex-1">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    :placeholder="t('stocks.searchPlaceholder')"
                    class="pl-9"
                />
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead
                    class="border-b border-sidebar-border/70 text-left text-xs tracking-wide text-muted-foreground uppercase dark:border-sidebar-border"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-medium">
                            {{ t('stocks.symbol') }}
                        </th>
                        <th class="px-4 py-2.5 font-medium">
                            {{ t('stocks.name') }}
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            {{ t('stocks.price') }}
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            {{ t('stocks.change') }}
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            {{ t('stocks.watch') }}
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                >
                    <tr
                        v-for="stock in stocks"
                        :key="stock.id"
                        class="transition-colors hover:bg-accent/40"
                    >
                        <td class="px-4 py-2.5">
                            <Link
                                :href="`/stocks/${stock.symbol}`"
                                class="flex items-center gap-2 font-semibold text-foreground hover:underline"
                            >
                                {{ stock.symbol }}
                                <MarketBadge :market="stock.market" />
                            </Link>
                        </td>
                        <td class="px-4 py-2.5 text-muted-foreground">
                            {{ stock.name }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right text-foreground tabular-nums"
                        >
                            {{ formatPrice(stock.price, stock.currency) }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <PriceChange
                                :change="null"
                                :change-percent="stock.change_percent"
                                :show-absolute="false"
                            />
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <Link
                                v-if="stock.in_watchlist"
                                :href="`/stocks/${stock.symbol}`"
                                class="inline-flex items-center gap-1 text-amber-500"
                                :title="t('stocks.inWatchlist')"
                            >
                                <Star class="size-4 fill-current" />
                            </Link>
                            <button
                                v-else
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-sidebar-border/70 px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground dark:border-sidebar-border"
                                @click="addToWatchlist(stock)"
                            >
                                <Plus class="size-3" /> {{ t('stocks.add') }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="stocks.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ t('stocks.noMatches') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
