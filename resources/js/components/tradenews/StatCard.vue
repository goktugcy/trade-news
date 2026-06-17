<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    label: string;
    value: string | number;
    icon?: Component;
    hint?: string;
}>();
</script>

<template>
    <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ label }}</span>
            <component :is="icon" v-if="icon" class="size-4 text-muted-foreground" />
        </div>
        <p class="mt-2 text-2xl font-semibold tabular-nums text-foreground">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
