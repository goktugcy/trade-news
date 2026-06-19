<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bookmark, BookmarkCheck, ThumbsDown, ThumbsUp } from '@lucide/vue';
import type { NewsCardData } from '@/types';

const props = defineProps<{ news: NewsCardData }>();

function react(value: 1 | -1) {
    router.post(`/news/${props.news.id}/react`, { value }, { preserveScroll: true });
}

function toggleSave() {
    if (props.news.is_saved) {
        router.delete(`/news/${props.news.id}/save`, { preserveScroll: true });
    } else {
        router.post(`/news/${props.news.id}/save`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="flex items-center gap-1">
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium transition-colors"
            :class="news.reaction === 1
                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
            :aria-pressed="news.reaction === 1"
            title="Like"
            @click="react(1)"
        >
            <ThumbsUp class="size-3.5" />
            {{ news.like_count }}
        </button>

        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium transition-colors"
            :class="news.reaction === -1
                ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
                : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
            :aria-pressed="news.reaction === -1"
            title="Dislike"
            @click="react(-1)"
        >
            <ThumbsDown class="size-3.5" />
            {{ news.dislike_count }}
        </button>

        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium transition-colors"
            :class="news.is_saved
                ? 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300'
                : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
            :aria-pressed="news.is_saved"
            :title="news.is_saved ? 'Saved' : 'Save'"
            @click="toggleSave"
        >
            <BookmarkCheck v-if="news.is_saved" class="size-3.5" />
            <Bookmark v-else class="size-3.5" />
            {{ news.is_saved ? 'Saved' : 'Save' }}
        </button>
    </div>
</template>
