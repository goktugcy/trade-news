<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PriceChange from '@/components/tradenews/PriceChange.vue';
import { formatPrice } from '@/lib/format';
import type { MarketStatusInfo, StockRow } from '@/types';

defineProps<{
    marketStatus: MarketStatusInfo[];
    movers: { gainers: StockRow[]; losers: StockRow[] };
}>();

const dotClass = (color: string): string =>
    ({
        emerald: 'bg-emerald-500',
        amber: 'bg-amber-500',
        slate: 'bg-slate-400',
        violet: 'bg-violet-500',
    })[color] ?? 'bg-slate-400';

const textClass = (color: string): string =>
    ({
        emerald: 'text-emerald-600 dark:text-emerald-400',
        amber: 'text-amber-600 dark:text-amber-400',
        slate: 'text-muted-foreground',
        violet: 'text-violet-600 dark:text-violet-400',
    })[color] ?? 'text-muted-foreground';
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Market status -->
        <section class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <h2 class="mb-3 text-sm font-semibold text-foreground">Market Status</h2>
            <ul class="space-y-2.5">
                <li v-for="m in marketStatus" :key="m.market" class="flex items-center justify-between text-sm">
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full" :class="dotClass(m.session_color)" />
                        <span class="font-medium text-foreground">{{ m.market }}</span>
                    </div>
                    <div class="text-right">
                        <div class="text-xs font-medium" :class="textClass(m.session_color)">{{ m.session_label }}</div>
                        <div class="text-[11px] text-muted-foreground">{{ m.opens_at }}–{{ m.closes_at }}</div>
                    </div>
                </li>
            </ul>
            <p v-if="marketStatus.length" class="mt-2 text-[11px] text-muted-foreground">
                Times shown in {{ marketStatus[0].display_timezone }}
            </p>
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
