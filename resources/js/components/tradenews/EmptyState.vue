<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        icon?: Component;
    }>(),
    {
        title: 'Nothing here yet',
        description: '',
    },
);
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 px-6 py-12 text-center dark:border-sidebar-border"
    >
        <div class="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <component :is="icon ?? Inbox" class="size-6" />
        </div>
        <div class="space-y-1">
            <p class="text-sm font-medium text-foreground">{{ title }}</p>
            <p v-if="description" class="mx-auto max-w-sm text-sm text-muted-foreground">{{ description }}</p>
        </div>
        <slot />
    </div>
</template>
