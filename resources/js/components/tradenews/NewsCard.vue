<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink, Flame, Languages, Loader2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import NewsCardActions from '@/components/tradenews/NewsCardActions.vue';
import SentimentBadge from '@/components/tradenews/SentimentBadge.vue';
import { useUserTimezone } from '@/composables/useUserTimezone';
import type { NewsCardData } from '@/types';

defineProps<{ news: NewsCardData }>();

const { relative, dateTime } = useUserTimezone();
const { t } = useI18n();
</script>

<template>
    <article
        class="group rounded-xl border border-sidebar-border/70 bg-card p-4 transition-colors hover:border-sidebar-border hover:bg-accent/40 dark:border-sidebar-border"
    >
        <div class="flex gap-3">
            <div
                v-if="news.image_url"
                class="mt-0.5 block h-20 w-24 shrink-0 overflow-hidden rounded-md bg-muted sm:h-24 sm:w-28"
            >
                <img
                    :src="news.image_url"
                    :alt="news.title"
                    class="size-full object-cover transition-transform group-hover:scale-[1.02]"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                />
            </div>

            <div class="min-w-0 flex-1">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span
                        v-if="news.source"
                        class="max-w-full truncate text-xs font-medium text-muted-foreground"
                        :title="news.sources.map((s) => s.name).filter(Boolean).join(', ')"
                    >
                        {{ news.source }}
                        <span
                            v-if="news.source_count > 1"
                            class="ml-1 rounded bg-muted px-1 py-0.5 text-[10px] font-medium text-foreground"
                        >{{ t('news.moreSources', { count: news.source_count - 1 }) }}</span>
                    </span>
                    <span
                        v-if="news.published_at"
                        class="text-xs text-muted-foreground"
                        :title="relative(news.published_at)"
                    >
                        {{ dateTime(news.published_at) }}
                    </span>
                    <span v-else-if="news.published_for_humans" class="text-xs text-muted-foreground">{{ news.published_for_humans }}</span>
                    <SentimentBadge :sentiment="news.sentiment" />
                    <span
                        v-if="news.importance >= 50"
                        class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"
                    >
                        <Flame class="size-3" /> {{ t('news.highImpact') }}
                    </span>
                    <span
                        v-if="news.translation_status === 'translating'"
                        class="inline-flex items-center gap-1 rounded-md bg-sky-100 px-1.5 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-500/15 dark:text-sky-300"
                    >
                        <Loader2 class="size-3 animate-spin" /> {{ t('common.translating') }}
                    </span>
                    <span
                        v-else-if="news.translation_status === 'translated'"
                        class="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                    >
                        <Languages class="size-3" /> {{ t('common.translated') }}
                    </span>
                </div>

                <h3 class="text-[15px] leading-snug font-semibold text-foreground">
                    <a
                        v-if="news.url"
                        :href="news.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="hover:underline"
                    >
                        {{ news.title }}
                    </a>
                    <span v-else>{{ news.title }}</span>
                </h3>

                <p v-if="news.summary" class="mt-1.5 line-clamp-2 text-sm text-muted-foreground">
                    <span
                        v-if="news.has_ai_summary"
                        class="mr-1 rounded bg-violet-100 px-1 py-0.5 text-[10px] font-medium text-violet-700 dark:bg-violet-500/15 dark:text-violet-300"
                    >AI</span>
                    {{ news.summary }}
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <Link
                        v-for="stock in news.stocks"
                        :key="stock.id"
                        :href="`/stocks/${stock.symbol}`"
                        class="inline-flex items-center rounded-md border border-sidebar-border/70 bg-background px-1.5 py-0.5 text-xs font-medium text-foreground transition-colors hover:bg-accent dark:border-sidebar-border"
                    >
                        ${{ stock.symbol }}
                    </Link>

                    <a
                        v-if="news.url"
                        :href="news.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ml-auto inline-flex items-center gap-1 text-xs text-foreground/70 hover:text-foreground"
                    >
                        {{ t('common.read') }} <ExternalLink class="size-3" />
                    </a>
                </div>

                <div class="mt-3 flex items-center justify-between border-t border-sidebar-border/60 pt-2.5 dark:border-sidebar-border">
                    <NewsCardActions :news="news" />
                </div>
            </div>
        </div>
    </article>
</template>
