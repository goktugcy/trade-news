<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bookmark, BookmarkCheck, ThumbsDown, ThumbsUp } from '@lucide/vue';
import { reactive, watch } from 'vue';
import type { NewsCardData } from '@/types';

const props = defineProps<{ news: NewsCardData }>();

// Local optimistic state — the template binds to this so clicks feel instant.
// The request only persists; it does NOT reload the (heavy) feed or shared props.
const local = reactive({
    reaction: props.news.reaction,
    like_count: props.news.like_count,
    dislike_count: props.news.dislike_count,
    is_saved: props.news.is_saved,
});

watch(
    () => props.news,
    (n) => {
        local.reaction = n.reaction;
        local.like_count = n.like_count;
        local.dislike_count = n.dislike_count;
        local.is_saved = n.is_saved;
    },
);

// Minimal round-trip: skip every other prop (the ticker, the 15-card feed).
const partial = { preserveScroll: true, preserveState: true, only: ['flash'] };

function react(value: 1 | -1) {
    // Mirror the server toggle locally (re-clicking the same value clears it).
    const prev = local.reaction;
    if (prev === 1) local.like_count--;
    if (prev === -1) local.dislike_count--;

    if (prev === value) {
        local.reaction = null;
    } else {
        local.reaction = value;
        if (value === 1) local.like_count++;
        else local.dislike_count++;
    }

    router.post(`/news/${props.news.id}/react`, { value }, partial);
}

function toggleSave() {
    local.is_saved = !local.is_saved;

    if (props.news.is_saved) {
        router.delete(`/news/${props.news.id}/save`, partial);
    } else {
        router.post(`/news/${props.news.id}/save`, {}, partial);
    }
}
</script>

<template>
    <div class="flex items-center gap-1">
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium transition-colors"
            :class="local.reaction === 1
                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
            :aria-pressed="local.reaction === 1"
            title="Like"
            @click="react(1)"
        >
            <ThumbsUp class="size-3.5" />
            {{ local.like_count }}
        </button>

        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium transition-colors"
            :class="local.reaction === -1
                ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
                : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
            :aria-pressed="local.reaction === -1"
            title="Dislike"
            @click="react(-1)"
        >
            <ThumbsDown class="size-3.5" />
            {{ local.dislike_count }}
        </button>

        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium transition-colors"
            :class="local.is_saved
                ? 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300'
                : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
            :aria-pressed="local.is_saved"
            :title="local.is_saved ? 'Saved' : 'Save'"
            @click="toggleSave"
        >
            <BookmarkCheck v-if="local.is_saved" class="size-3.5" />
            <Bookmark v-else class="size-3.5" />
            {{ local.is_saved ? 'Saved' : 'Save' }}
        </button>
    </div>
</template>
