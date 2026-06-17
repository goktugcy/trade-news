<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PriceChange from '@/components/tradenews/PriceChange.vue';
import { formatPrice } from '@/lib/format';
import type { MarketStatusInfo, StockRow } from '@/types';

defineProps<{
    marketStatus: MarketStatusInfo[];
    movers: { gainers: StockRow[]; losers: StockRow[] };
}>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Market status -->
        <section class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <h2 class="mb-3 text-sm font-semibold text-foreground">Market Status</h2>
            <ul class="space-y-2">
                <li v-for="m in marketStatus" :key="m.market" class="flex items-center justify-between text-sm">
                    <div class="flex items-center gap-2">
                        <span
                            class="size-2 rounded-full"
                            :class="m.is_open ? 'bg-emerald-500' : 'bg-rose-500'"
                        />
                        <span class="font-medium text-foreground">{{ m.market }}</span>
                    </div>
                    <div class="text-right text-xs text-muted-foreground">
                        <span :class="m.is_open ? 'text-emerald-600 dark:text-emerald-400' : ''">
                            {{ m.is_open ? 'Open' : 'Closed' }}
                        </span>
                        · {{ m.local_time }}
                    </div>
                </li>
            </ul>
        </section>

        <!-- Top movers -->
        <section class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <header class="border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                <h2 class="text-sm font-semibold text-foreground">Top Movers</h2>
            </header>
            <div class="grid grid-cols-1 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                <div class="p-3">
                    <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-emerald-600 dark:text-emerald-400">Gainers</p>
                    <ul class="space-y-1">
                        <li v-for="s in movers.gainers" :key="`g-${s.id}`" class="flex items-center justify-between text-sm">
                            <Link :href="`/stocks/${s.symbol}`" class="font-medium text-foreground hover:underline">{{ s.symbol }}</Link>
                            <div class="flex items-center gap-2">
                                <span class="tabular-nums text-muted-foreground">{{ formatPrice(s.price, s.currency) }}</span>
                                <PriceChange :change="null" :change-percent="s.change_percent" :show-absolute="false" />
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="p-3">
                    <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-rose-600 dark:text-rose-400">Losers</p>
                    <ul class="space-y-1">
                        <li v-for="s in movers.losers" :key="`l-${s.id}`" class="flex items-center justify-between text-sm">
                            <Link :href="`/stocks/${s.symbol}`" class="font-medium text-foreground hover:underline">{{ s.symbol }}</Link>
                            <div class="flex items-center gap-2">
                                <span class="tabular-nums text-muted-foreground">{{ formatPrice(s.price, s.currency) }}</span>
                                <PriceChange :change="null" :change-percent="s.change_percent" :show-absolute="false" />
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </div>
</template>
