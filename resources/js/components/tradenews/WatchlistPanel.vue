<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LivePrice from '@/components/tradenews/LivePrice.vue';
import MarketBadge from '@/components/tradenews/MarketBadge.vue';
import PriceChange from '@/components/tradenews/PriceChange.vue';
import type { StockRow } from '@/types';

defineProps<{ items: StockRow[]; title?: string }>();

const { t } = useI18n();
</script>

<template>
    <section class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
        <header class="flex items-center justify-between border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
            <h2 class="text-sm font-semibold text-foreground">{{ title ?? t('watchlist.title') }}</h2>
            <Link href="/watchlist" class="text-xs text-muted-foreground hover:text-foreground">{{ t('watchlist.manage') }}</Link>
        </header>

        <div v-if="items.length === 0" class="px-4 py-6 text-center text-sm text-muted-foreground">
            <p>{{ t('watchlist.noStocks') }}</p>
            <Link href="/stocks" class="mt-1 inline-block text-foreground hover:underline">{{ t('news.browseStocks') }} →</Link>
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
                        <p class="text-sm font-medium text-foreground">
                            <LivePrice :value="stock.price" :currency="stock.currency" />
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
