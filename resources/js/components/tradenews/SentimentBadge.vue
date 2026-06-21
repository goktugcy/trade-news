<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { SentimentValue } from '@/types';

const props = defineProps<{ sentiment: SentimentValue | null }>();

const { t } = useI18n();

const map: Record<SentimentValue, { class: string; icon: string }> = {
    positive: {
        class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        icon: '▲',
    },
    neutral: {
        class: 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300',
        icon: '■',
    },
    negative: {
        class: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        icon: '▼',
    },
};

const meta = computed(() => (props.sentiment ? map[props.sentiment] : null));
const label = computed(() => (props.sentiment ? t(`sentiment.${props.sentiment}`) : ''));
</script>

<template>
    <span
        v-if="meta"
        class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium"
        :class="meta.class"
    >
        <span class="text-[8px] leading-none">{{ meta.icon }}</span>
        {{ label }}
    </span>
</template>
