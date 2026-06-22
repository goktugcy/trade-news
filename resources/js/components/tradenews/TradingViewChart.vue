<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAppearance } from '@/composables/useAppearance';

const props = defineProps<{
    symbol: string;
    market: string;
}>();

const { locale } = useI18n();
const { resolvedAppearance } = useAppearance();

const container = ref<HTMLDivElement | null>(null);

// Map a stock to its TradingView "EXCHANGE:SYMBOL" ticker. Dots are kept for
// class shares (e.g. BRK.B); anything else non-alphanumeric is stripped.
function tvSymbol(): string {
    const exchange = props.market === 'BIST' ? 'BIST' : 'NASDAQ';
    const clean = props.symbol.toUpperCase().replace(/[^A-Z0-9.]/g, '');

    return `${exchange}:${clean}`;
}

function render(): void {
    const host = container.value;

    if (!host) {
        return;
    }

    // Tear down any previous widget before re-injecting (symbol/theme change).
    host.innerHTML = '';

    const widget = document.createElement('div');
    widget.className = 'tradingview-widget-container__widget';
    widget.style.height = 'calc(100% - 24px)';
    widget.style.width = '100%';
    host.appendChild(widget);

    // TradingView attribution — required by the free widget's license.
    const copyright = document.createElement('div');
    copyright.className = 'tradingview-widget-copyright';
    copyright.innerHTML =
        '<a href="https://www.tradingview.com/" rel="noopener nofollow" target="_blank" class="text-xs text-muted-foreground hover:underline">TradingView</a>';
    host.appendChild(copyright);

    const script = document.createElement('script');
    script.src =
        'https://s3.tradingview.com/external-embedding/embed-widget-advanced-chart.js';
    script.type = 'text/javascript';
    script.async = true;
    script.innerHTML = JSON.stringify({
        autosize: true,
        symbol: tvSymbol(),
        interval: 'D',
        timezone: 'Etc/UTC',
        theme: resolvedAppearance.value,
        style: '1',
        locale: locale.value === 'tr' ? 'tr' : 'en',
        allow_symbol_change: false,
        hide_side_toolbar: false,
        save_image: false,
        support_host: 'https://www.tradingview.com',
    });
    host.appendChild(script);
}

onMounted(render);

watch(
    () => [props.symbol, props.market, resolvedAppearance.value, locale.value],
    render,
);

onBeforeUnmount(() => {
    if (container.value) {
        container.value.innerHTML = '';
    }
});
</script>

<template>
    <div ref="container" class="tradingview-widget-container h-full w-full" />
</template>
