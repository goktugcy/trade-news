<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    type CandlestickData,
    CandlestickSeries,
    ColorType,
    createChart,
    HistogramSeries,
    type IChartApi,
    type ISeriesApi,
    type UTCTimestamp,
} from 'lightweight-charts';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Skeleton } from '@/components/ui/skeleton';
import { candles as stockCandles } from '@/routes/stocks';
import type { Candle } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    symbol: string;
    timeframe: string;
    range: string;
}>();

const container = ref<HTMLDivElement | null>(null);
const loading = ref(true);
const empty = ref(false);
const page = usePage();
const autoRefreshSeconds = computed(() => Number(page.props.dataPreferences?.auto_refresh_seconds ?? 60));

let chart: IChartApi | null = null;
let candleSeries: ISeriesApi<'Candlestick'> | null = null;
let volumeSeries: ISeriesApi<'Histogram'> | null = null;
let themeObserver: MutationObserver | null = null;
let refreshTimer: ReturnType<typeof window.setInterval> | null = null;

function isDark(): boolean {
    return document.documentElement.classList.contains('dark');
}

function themeOptions() {
    const dark = isDark();

    return {
        layout: {
            background: { type: ColorType.Solid, color: 'transparent' },
            textColor: dark ? '#a1a1aa' : '#52525b',
        },
        grid: {
            vertLines: { color: dark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
            horzLines: { color: dark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
        },
        rightPriceScale: { borderColor: dark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)' },
        timeScale: { borderColor: dark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)' },
    };
}

function buildChart() {
    if (!container.value) {
        return;
    }

    chart = createChart(container.value, {
        autoSize: true,
        height: 360,
        ...themeOptions(),
        crosshair: { mode: 1 },
    });

    candleSeries = chart.addSeries(CandlestickSeries, {
        upColor: '#10b981',
        downColor: '#f43f5e',
        borderUpColor: '#10b981',
        borderDownColor: '#f43f5e',
        wickUpColor: '#10b981',
        wickDownColor: '#f43f5e',
    });

    volumeSeries = chart.addSeries(HistogramSeries, {
        priceFormat: { type: 'volume' },
        priceScaleId: 'volume',
        color: 'rgba(99,102,241,0.4)',
    });
    chart.priceScale('volume').applyOptions({
        scaleMargins: { top: 0.8, bottom: 0 },
    });
}

async function loadData() {
    loading.value = true;
    empty.value = false;

    try {
        const res = await fetch(stockCandles.url(props.symbol, { query: { timeframe: props.timeframe, range: props.range } }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const json = await res.json();
        const candles: Candle[] = json.candles ?? [];

        if (candles.length === 0) {
            candleSeries?.setData([]);
            volumeSeries?.setData([]);
            empty.value = true;
            return;
        }

        const candleData: CandlestickData[] = candles.map((c) => ({
            time: c.time as UTCTimestamp,
            open: c.open,
            high: c.high,
            low: c.low,
            close: c.close,
        }));

        const volumeData = candles.map((c) => ({
            time: c.time as UTCTimestamp,
            value: c.volume,
            color: c.close >= c.open ? 'rgba(16,185,129,0.35)' : 'rgba(244,63,94,0.35)',
        }));

        candleSeries?.setData(candleData);
        volumeSeries?.setData(volumeData);
        chart?.timeScale().fitContent();
    } catch {
        candleSeries?.setData([]);
        volumeSeries?.setData([]);
        empty.value = true;
    } finally {
        loading.value = false;
    }
}

function clearRefreshTimer() {
    if (refreshTimer !== null) {
        window.clearInterval(refreshTimer);
        refreshTimer = null;
    }
}

function configureRefreshTimer() {
    clearRefreshTimer();

    if (autoRefreshSeconds.value <= 0) {
        return;
    }

    refreshTimer = window.setInterval(() => {
        if (!loading.value) {
            loadData();
        }
    }, autoRefreshSeconds.value * 1000);
}

onMounted(() => {
    buildChart();
    loadData();
    configureRefreshTimer();

    themeObserver = new MutationObserver(() => chart?.applyOptions(themeOptions()));
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

watch(() => [props.symbol, props.timeframe, props.range], () => loadData());
watch(autoRefreshSeconds, () => configureRefreshTimer());

onBeforeUnmount(() => {
    clearRefreshTimer();
    themeObserver?.disconnect();
    chart?.remove();
    chart = null;
});
</script>

<template>
    <div class="relative w-full">
        <div ref="container" class="h-[360px] w-full" />
        <div v-if="loading" class="absolute inset-0 flex items-center justify-center">
            <Skeleton class="h-[340px] w-full rounded-lg" />
        </div>
        <div
            v-else-if="empty"
            class="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground"
        >
            {{ t('stocks.noPriceHistory') }}
        </div>
    </div>
</template>
