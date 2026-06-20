<script setup lang="ts">
import { Newspaper } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/tradenews/EmptyState.vue';
import NewsCard from '@/components/tradenews/NewsCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { NewsCardData } from '@/types';

withDefaults(
    defineProps<{
        news: NewsCardData[];
        loading?: boolean;
        emptyTitle?: string;
        emptyDescription?: string;
    }>(),
    {
        loading: false,
        emptyTitle: undefined,
        emptyDescription: undefined,
    },
);

const { t } = useI18n();
</script>

<template>
    <div class="flex flex-col gap-3">
        <template v-if="loading">
            <div
                v-for="n in 4"
                :key="n"
                class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            >
                <div class="mb-3 flex gap-2">
                    <Skeleton class="h-4 w-12 rounded-md" />
                    <Skeleton class="h-4 w-16 rounded-md" />
                </div>
                <Skeleton class="h-4 w-3/4 rounded" />
                <Skeleton class="mt-2 h-3 w-full rounded" />
                <Skeleton class="mt-1.5 h-3 w-2/3 rounded" />
            </div>
        </template>

        <template v-else-if="news.length === 0">
            <EmptyState :title="emptyTitle ?? t('news.noNews')" :description="emptyDescription ?? t('news.noNewsDescription')" :icon="Newspaper" />
        </template>

        <template v-else>
            <NewsCard v-for="item in news" :key="item.id" :news="item" />
        </template>
    </div>
</template>
