<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import MarketBadge from '@/components/tradenews/MarketBadge.vue';
import PriceChange from '@/components/tradenews/PriceChange.vue';
import { formatPrice } from '@/lib/format';
import type { StockRow } from '@/types';

defineProps<{ items: StockRow[]; title?: string }>();
</script>

<template>
    <section class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
        <header class="flex items-center justify-between border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
            <h2 class="text-sm font-semibold text-foreground">{{ title ?? 'Watchlist' }}</h2>
            <Link href="/watchlist" class="text-xs text-muted-foreground hover:text-foreground">Manage</Link>
        </header>

        <div v-if="items.length === 0" class="px-4 py-6 text-center text-sm text-muted-foreground">
            <p>No stocks yet.</p>
            <Link href="/stocks" class="mt-1 inline-block text-foreground hover:underline">Browse stocks →</Link>
        </div>

        <ul v-else class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
            <li v-for="stock in items" :key="stock.id">
                <Link
                    :href="`/stocks/${stock.symbol}`"
                    class="flex items-center justify-between gap-2 px-4 py-2.5 transition-colors hover:bg-accent/50"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5">
                            <span class="text-sm font-semibold text-foreground">{{ stock.symbol }}</span>
                            <MarketBadge :market="stock.market" />
                        </div>
                        <p class="truncate text-xs text-muted-foreground">{{ stock.name }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium tabular-nums text-foreground">
                            {{ formatPrice(stock.price, stock.currency) }}
                        </p>
                        <PriceChange
                            class="text-xs"
                            :change="null"
                            :change-percent="stock.change_percent"
                            :show-absolute="false"
                        />
                    </div>
                </Link>
            </li>
        </ul>
    </section>
</template>
