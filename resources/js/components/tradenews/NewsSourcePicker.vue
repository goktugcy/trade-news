<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import NewsSourcePreferenceController from '@/actions/App/Http/Controllers/NewsSourcePreferenceController';
import type { NewsSourcePref } from '@/types';

defineProps<{ sources: NewsSourcePref[] }>();

const { t } = useI18n();

function toggle(source: NewsSourcePref, enabled: boolean) {
    // Optimistically flip the checkbox; reload only the feed (skip ticker/options).
    source.enabled = enabled;
    router.patch(
        NewsSourcePreferenceController.update.url(source.id),
        { enabled },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['news'],
            onError: () => {
                source.enabled = !enabled;
            },
        },
    );
}
</script>

<template>
    <div class="rounded-lg border border-sidebar-border/70 bg-background p-3 dark:border-sidebar-border">
        <p class="mb-2 text-xs font-medium text-muted-foreground">
            {{ t('news.sourceHelp') }}
        </p>
        <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
            <label
                v-for="source in sources"
                :key="source.id"
                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent/60"
            >
                <input
                    type="checkbox"
                    class="size-4 rounded border-sidebar-border text-foreground"
                    :checked="source.enabled"
                    @change="toggle(source, ($event.target as HTMLInputElement).checked)"
                />
                <span class="min-w-0 flex-1 truncate text-foreground">{{ source.name }}</span>
                <span
                    v-if="source.language"
                    class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground"
                >
                    {{ source.language }}
                </span>
            </label>
        </div>
        <p v-if="!sources.length" class="text-sm text-muted-foreground">{{ t('common.noSources') }}</p>
    </div>
</template>
