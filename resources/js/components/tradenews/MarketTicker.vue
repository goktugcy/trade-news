<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatPercent, formatPrice } from '@/lib/format';
import type { TickerItem } from '@/types';

const page = usePage();

const items = computed<TickerItem[]>(() => (page.props.ticker as TickerItem[] | undefined) ?? []);

// Keep the scroll speed roughly constant regardless of how many items there are.
const duration = computed(() => `${Math.max(24, items.value.length * 3)}s`);

const isUp = (item: TickerItem) => (item.change_percent ?? 0) >= 0;
</script>

<template>
    <div
        v-if="items.length"
        class="ticker group/ticker relative flex h-9 shrink-0 items-center overflow-hidden border-b border-sidebar-border/70 bg-card/60 backdrop-blur dark:border-sidebar-border"
        aria-label="Top gainers and losers"
    >
        <div class="ticker-track flex shrink-0" :style="{ animationDuration: duration }">
            <!-- Two identical copies → seamless -50% loop -->
            <div
                v-for="copy in 2"
                :key="copy"
                class="ticker-copy flex shrink-0 items-center gap-6 pr-6"
                :aria-hidden="copy === 2 ? 'true' : undefined"
            >
                <Link
                    v-for="item in items"
                    :key="`${copy}-${item.symbol}`"
                    :href="`/stocks/${item.symbol}`"
                    class="flex items-center gap-1.5 whitespace-nowrap text-xs transition-opacity hover:opacity-80"
                >
                    <span :class="isUp(item) ? 'text-emerald-500' : 'text-rose-500'">{{ isUp(item) ? '▲' : '▼' }}</span>
                    <span class="font-semibold text-foreground">{{ item.symbol }}</span>
                    <span class="tabular-nums text-muted-foreground">{{ formatPrice(item.price, item.currency) }}</span>
                    <span class="tabular-nums" :class="isUp(item) ? 'text-emerald-500' : 'text-rose-500'">{{ formatPercent(item.change_percent) }}</span>
                </Link>
            </div>
        </div>

        <!-- Edge fades -->
        <div class="pointer-events-none absolute inset-y-0 left-0 w-10 bg-gradient-to-r from-background to-transparent" />
        <div class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-background to-transparent" />
    </div>
</template>

<style scoped>
.ticker-track {
    animation-name: ticker-scroll;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
    will-change: transform;
}

.group\/ticker:hover .ticker-track {
    animation-play-state: paused;
}

@keyframes ticker-scroll {
    from {
        transform: translateX(0);
    }
    to {
        transform: translateX(-50%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .ticker-track {
        animation: none;
    }
}
</style>
