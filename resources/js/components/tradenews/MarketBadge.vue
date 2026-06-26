<script setup lang="ts">
import type { Market } from '@/types';

defineProps<{ market: Market | null }>();
</script>

<template>
    <span
        v-if="market"
        class="inline-flex items-center rounded-md bg-violet-100 px-1.5 py-0.5 text-[11px] font-semibold tracking-wide text-violet-700 dark:bg-violet-500/15 dark:text-violet-300"
    >
        {{ market }}
    </span>
</template>
