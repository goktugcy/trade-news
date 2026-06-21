<script setup lang="ts">
import { ref, watch } from 'vue';
import { formatPrice } from '@/lib/format';

const props = withDefaults(
    defineProps<{
        value: number | null;
        currency?: string;
        flash?: boolean;
    }>(),
    { currency: 'USD', flash: true },
);

// Briefly flash green/red when the price ticks up/down (Midas/X feel).
const flashClass = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.value,
    (next, prev) => {
        if (!props.flash || next === null || prev === null || prev === undefined || next === prev) {
            return;
        }

        flashClass.value = next > prev ? 'price-flash-up' : 'price-flash-down';

        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(() => (flashClass.value = ''), 700);
    },
);
</script>

<template>
    <span class="rounded px-1 tabular-nums transition-colors duration-300" :class="flashClass">
        {{ formatPrice(value, currency) }}
    </span>
</template>

<style scoped>
.price-flash-up {
    background-color: color-mix(in oklab, var(--color-emerald-500, #10b981) 22%, transparent);
}

.price-flash-down {
    background-color: color-mix(in oklab, var(--color-rose-500, #f43f5e) 22%, transparent);
}
</style>
