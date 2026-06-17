<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, BellOff, Plus, Search, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import EmptyState from '@/components/tradenews/EmptyState.vue';
import MarketBadge from '@/components/tradenews/MarketBadge.vue';
import PriceChange from '@/components/tradenews/PriceChange.vue';
import { Input } from '@/components/ui/input';
import { formatPrice } from '@/lib/format';
import type { StockRow } from '@/types';

defineProps<{ items: StockRow[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Watchlist', href: '/watchlist' }] },
});

type SearchResult = { id: number; symbol: string; name: string; market: 'BIST' | 'NASDAQ' };

const query = ref('');
const results = ref<SearchResult[]>([]);
const open = ref(false);

let debounce: ReturnType<typeof setTimeout> | undefined;
watch(query, (value) => {
    clearTimeout(debounce);
    if (!value) {
        results.value = [];
        open.value = false;
        return;
    }
    debounce = setTimeout(async () => {
        const res = await fetch(`/stocks/search?q=${encodeURIComponent(value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const json = await res.json();
        results.value = json.results ?? [];
        open.value = true;
    }, 250);
});

function add(stockId: number) {
    router.post('/watchlist', { stock_id: stockId }, {
        preserveScroll: true,
        onSuccess: () => {
            query.value = '';
            results.value = [];
            open.value = false;
        },
    });
}

function remove(item: StockRow) {
    if (item.watchlist_id) {
        router.delete(`/watchlist/${item.watchlist_id}`, { preserveScroll: true });
    }
}

function toggleAlert(item: StockRow) {
    if (item.watchlist_id) {
        router.patch(`/watchlist/${item.watchlist_id}/alert`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Watchlist" />

    <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-lg font-semibold text-foreground">Manage Watchlist</h1>

        <!-- Add box -->
        <div class="relative">
            <div class="relative">
                <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="query" placeholder="Search a stock to add (e.g. AAPL, Aselsan)…" class="pl-9" @focus="open = results.length > 0" />
            </div>
            <ul
                v-if="open && results.length > 0"
                class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-sidebar-border/70 bg-popover shadow-lg dark:border-sidebar-border"
            >
                <li v-for="r in results" :key="r.id">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition-colors hover:bg-accent"
                        @click="add(r.id)"
                    >
                        <span class="flex items-center gap-2">
                            <span class="font-semibold text-foreground">{{ r.symbol }}</span>
                            <MarketBadge :market="r.market" />
                            <span class="text-muted-foreground">{{ r.name }}</span>
                        </span>
                        <Plus class="size-4 text-muted-foreground" />
                    </button>
                </li>
            </ul>
        </div>

        <EmptyState
            v-if="items.length === 0"
            title="Your watchlist is empty"
            description="Search above or browse the stock list to start following companies."
        >
            <Link href="/stocks" class="text-sm font-medium text-foreground hover:underline">Browse stocks →</Link>
        </EmptyState>

        <div v-else class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <ul class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                <li v-for="item in items" :key="item.id" class="flex items-center gap-3 px-4 py-3">
                    <Link :href="`/stocks/${item.symbol}`" class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-foreground">{{ item.symbol }}</span>
                            <MarketBadge :market="item.market" />
                        </div>
                        <p class="truncate text-xs text-muted-foreground">{{ item.name }}</p>
                    </Link>

                    <div class="text-right">
                        <p class="text-sm font-medium tabular-nums text-foreground">{{ formatPrice(item.price, item.currency) }}</p>
                        <PriceChange :change="null" :change-percent="item.change_percent" :show-absolute="false" class="text-xs" />
                    </div>

                    <button
                        type="button"
                        class="rounded-md p-2 transition-colors hover:bg-accent"
                        :class="item.alerts_enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'"
                        :title="item.alerts_enabled ? 'Alerts on' : 'Alerts off'"
                        @click="toggleAlert(item)"
                    >
                        <component :is="item.alerts_enabled ? Bell : BellOff" class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded-md p-2 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                        title="Remove"
                        @click="remove(item)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
