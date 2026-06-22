<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Bell, BellOff, Languages, Loader2, Star } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import LivePrice from '@/components/tradenews/LivePrice.vue';
import MarketBadge from '@/components/tradenews/MarketBadge.vue';
import NewsFeed from '@/components/tradenews/NewsFeed.vue';
import PriceChange from '@/components/tradenews/PriceChange.vue';
import TradingViewChart from '@/components/tradenews/TradingViewChart.vue';
import TypewriterText from '@/components/tradenews/TypewriterText.vue';
import { Button } from '@/components/ui/button';
import { useLiveQuotes } from '@/composables/useLiveQuotes';
import { formatNumber, formatPrice } from '@/lib/format';
import { postJson } from '@/lib/http';
import type { NewsCardData, StockRow } from '@/types';

type AiStockAnalysis = {
    signal: string;
    signal_label: string;
    signal_color: string;
    confidence: number;
    horizon: string | null;
    estimated_price_low: number | null;
    estimated_price_high: number | null;
    estimated_price: number | null;
    currency: string | null;
    summary: string | null;
    drivers: string[];
    risks: string[];
    disclaimer: string;
    translation_locale: 'en' | 'tr' | null;
    translation_status: 'translated' | 'original';
    can_translate: boolean;
    generated_at: string | null;
    is_stale: boolean;
    ai_enabled: boolean;
};

const props = defineProps<{
    stock: StockRow;
    news: NewsCardData[];
    analysis: AiStockAnalysis | null;
}>();

const { t } = useI18n();

const signalClasses: Record<string, string> = {
    emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    rose: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    slate: 'bg-muted text-muted-foreground',
};

// Live hero price (no reload). Falls back to the SSR values until the first poll.
const { quotes } = useLiveQuotes(() => [props.stock.symbol]);
const liveStock = computed<StockRow>(() => {
    const quote = quotes.value[props.stock.symbol];

    return quote
        ? { ...props.stock, price: quote.price, change: quote.change, change_percent: quote.change_percent, quote_at: quote.quote_at }
        : props.stock;
});

// Local analysis so an on-demand translate updates the card in place.
const analysisLocal = ref<AiStockAnalysis | null>(props.analysis);
watch(() => props.analysis, (next) => (analysisLocal.value = next));

const analysisTranslating = ref(false);
const analysisRevealKey = ref(0);

async function translateAnalysis(): Promise<void> {
    if (!analysisLocal.value || analysisTranslating.value) {
        return;
    }

    analysisTranslating.value = true;

    try {
        const res = await postJson<{ ok: boolean; analysis: AiStockAnalysis | null }>(`/stocks/${props.stock.symbol}/analysis/translate`);

        if (res.ok && res.analysis) {
            analysisLocal.value = res.analysis;
            analysisRevealKey.value += 1;
        }
    } catch {
        // keep original on failure
    } finally {
        analysisTranslating.value = false;
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Stocks', href: '/stocks' },
        ],
    },
});

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
                    {{ t('stocks.marketCap') }} {{ formatNumber(stock.market_cap) }}
                    <a v-if="stock.website" :href="stock.website" target="_blank" rel="noopener" class="ml-1 text-foreground hover:underline">· {{ t('stocks.website') }} ↗</a>
                </p>
            </div>

            <div class="flex flex-col items-start gap-2 sm:items-end">
                <div class="flex items-baseline gap-2 text-3xl font-bold text-foreground">
                    <LivePrice :value="liveStock.price" :currency="liveStock.currency" />
                </div>
                <PriceChange :change="liveStock.change" :change-percent="liveStock.change_percent" />

                <div class="mt-1 flex items-center gap-2">
                    <Button v-if="!stock.in_watchlist" size="sm" variant="outline" @click="addToWatchlist">
                        <Star class="size-4" /> {{ t('stocks.addToWatchlist') }}
                    </Button>
                    <template v-else>
                        <Button size="sm" variant="outline" @click="removeFromWatchlist">
                            <Star class="size-4 fill-current text-amber-500" /> {{ t('stocks.watching') }}
                        </Button>
                        <Button
                            size="sm"
                            :variant="stock.alerts_enabled ? 'default' : 'outline'"
                            @click="toggleAlert"
                        >
                            <component :is="stock.alerts_enabled ? Bell : BellOff" class="size-4" />
                            {{ stock.alerts_enabled ? t('stocks.alertsOn') : t('stocks.alertsOff') }}
                        </Button>
                    </template>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <h2 class="mb-3 text-sm font-semibold text-foreground">{{ t('stocks.priceChart') }}</h2>
            <div class="h-[48vh] min-h-96 w-full sm:h-[54vh] xl:h-160">
                <TradingViewChart :symbol="stock.symbol" :market="stock.market" />
            </div>
        </div>

        <!-- AI analysis -->
        <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-foreground">{{ t('stocks.aiAnalysis') }}</h2>
                <div v-if="analysisLocal" class="flex items-center gap-2">
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="signalClasses[analysisLocal.signal_color] ?? signalClasses.slate">
                        {{ analysisLocal.signal_label }} · {{ analysisLocal.confidence }}%
                    </span>
                    <span v-if="analysisLocal.is_stale" class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                        {{ analysisLocal.ai_enabled ? t('stocks.stale') : t('stocks.aiOffStale') }}
                    </span>
                    <span
                        v-if="analysisTranslating"
                        class="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-500/15 dark:text-sky-300"
                    >
                        <Loader2 class="size-3 animate-spin" /> {{ t('common.translating') }}
                    </span>
                    <span
                        v-else-if="analysisLocal.translation_status === 'translated'"
                        class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                    >
                        <Languages class="size-3" /> {{ t('common.translated') }}
                    </span>
                    <button
                        v-else-if="analysisLocal.can_translate"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-sky-700 transition-colors hover:bg-sky-100 dark:text-sky-300 dark:hover:bg-sky-500/15"
                        @click="translateAnalysis"
                    >
                        <Languages class="size-3" /> {{ t('news.translateTo') }}
                    </button>
                </div>
            </div>

            <template v-if="analysisLocal">
                <p v-if="analysisLocal.summary" class="text-sm leading-relaxed text-foreground">
                    <TypewriterText :text="analysisLocal.summary" :trigger="analysisRevealKey" />
                </p>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div v-if="analysisLocal.estimated_price !== null || analysisLocal.estimated_price_low !== null" class="rounded-lg border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border">
                        <p class="text-xs text-muted-foreground">{{ t('stocks.estimatedPrice') }}{{ analysisLocal.horizon ? ` · ${analysisLocal.horizon}` : '' }}</p>
                        <p class="mt-1 font-semibold tabular-nums text-foreground">
                            <template v-if="analysisLocal.estimated_price_low !== null && analysisLocal.estimated_price_high !== null">
                                {{ formatPrice(analysisLocal.estimated_price_low, analysisLocal.currency ?? stock.currency) }}
                                – {{ formatPrice(analysisLocal.estimated_price_high, analysisLocal.currency ?? stock.currency) }}
                            </template>
                            <template v-else-if="analysisLocal.estimated_price !== null">
                                {{ formatPrice(analysisLocal.estimated_price, analysisLocal.currency ?? stock.currency) }}
                            </template>
                        </p>
                    </div>

                    <div v-if="analysisLocal.drivers.length" class="rounded-lg border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border">
                        <p class="text-xs text-muted-foreground">{{ t('stocks.keyDrivers') }}</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-4 text-foreground">
                            <li v-for="(driver, i) in analysisLocal.drivers" :key="i">{{ driver }}</li>
                        </ul>
                    </div>

                    <div v-if="analysisLocal.risks.length" class="rounded-lg border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border">
                        <p class="text-xs text-muted-foreground">{{ t('stocks.risks') }}</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-4 text-foreground">
                            <li v-for="(risk, i) in analysisLocal.risks" :key="i">{{ risk }}</li>
                        </ul>
                    </div>
                </div>

                <p v-if="analysisLocal.generated_at" class="mt-3 text-xs text-muted-foreground">{{ t('stocks.generated') }} {{ analysisLocal.generated_at }}</p>
                <p class="mt-2 text-[11px] leading-snug text-muted-foreground">{{ analysisLocal.disclaimer }}</p>
            </template>

            <p v-else class="text-sm text-muted-foreground">
                {{ t('stocks.noAiAnalysis') }}
            </p>
        </div>

        <!-- Related news -->
        <div class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold text-foreground">{{ t('stocks.relatedNews') }}</h2>
            <NewsFeed
                :news="news"
                scope="all"
                :live-filters="{ stock: stock.symbol }"
                :empty-title="t('stocks.noRelatedNews')"
                :empty-description="t('stocks.noRelatedNewsDescription')"
            />
        </div>
    </div>
</template>
