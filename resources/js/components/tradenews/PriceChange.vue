<script setup lang="ts">
import { computed } from 'vue';
import { changeClass, formatChange, formatPercent } from '@/lib/format';

const props = withDefaults(
    defineProps<{
        change: number | null;
        changePercent: number | null;
        showAbsolute?: boolean;
    }>(),
    { showAbsolute: true },
);

const cls = computed(() => changeClass(props.changePercent));
</script>

<template>
    <span class="inline-flex items-center gap-1 font-medium tabular-nums" :class="cls">
        <span v-if="showAbsolute && change !== null">{{ formatChange(change) }}</span>
        <span>{{ formatPercent(changePercent) }}</span>
    </span>
</template>
