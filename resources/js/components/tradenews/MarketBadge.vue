<script setup lang="ts">
import { computed } from 'vue';
import type { Market } from '@/types';

const props = defineProps<{ market: Market | null }>();

const classes = computed(() =>
    props.market === 'BIST'
        ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
        : 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
);
</script>

<template>
    <span
        v-if="market"
        class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-semibold tracking-wide"
        :class="classes"
    >
        {{ market }}
    </span>
</template>
