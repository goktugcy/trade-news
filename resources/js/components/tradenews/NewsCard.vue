<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink, Flame } from '@lucide/vue';
import MarketBadge from '@/components/tradenews/MarketBadge.vue';
import SentimentBadge from '@/components/tradenews/SentimentBadge.vue';
import type { NewsCardData } from '@/types';

defineProps<{ news: NewsCardData }>();
</script>

<template>
    <article
        class="group rounded-xl border border-sidebar-border/70 bg-card p-4 transition-colors hover:border-sidebar-border hover:bg-accent/40 dark:border-sidebar-border"
    >
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <MarketBadge :market="news.market" />
            <SentimentBadge :sentiment="news.sentiment" />
            <span
                v-if="news.importance >= 50"
                class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"
            >
                <Flame class="size-3" /> High impact
            </span>
            <span class="ml-auto text-xs text-muted-foreground">{{ news.published_for_humans }}</span>
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

            <span class="ml-auto flex items-center gap-3 text-xs text-muted-foreground">
                <span v-if="news.source">{{ news.source }}</span>
                <a
                    v-if="news.url"
                    :href="news.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-foreground/70 hover:text-foreground"
                >
                    Read <ExternalLink class="size-3" />
                </a>
            </span>
        </div>
    </article>
</template>
