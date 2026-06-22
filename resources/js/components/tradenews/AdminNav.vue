<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

const { isCurrentUrl } = useCurrentUrl();

const tabs = [
    { title: 'Overview', href: '/admin' },
    { title: 'Stocks', href: '/admin/stocks' },
    { title: 'News Sources', href: '/admin/news-sources' },
    { title: 'API Providers', href: '/admin/providers' },
    { title: 'Market Data', href: '/admin/market-data' },
    { title: 'AI Settings', href: '/admin/ai-settings' },
    { title: 'Provider Events', href: '/admin/provider-events' },
    { title: 'Sync Logs', href: '/admin/sync-logs' },
    { title: 'Users', href: '/admin/users' },
    { title: 'Jobs & Health', href: '/admin/jobs' },
    { title: 'Notifications', href: '/admin/notifications' },
    { title: 'System', href: '/admin/system-notifications' },
];
</script>

<template>
    <nav class="flex flex-wrap gap-1 border-b border-sidebar-border/70 pb-2 dark:border-sidebar-border">
        <Link
            v-for="tab in tabs"
            :key="tab.href"
            :href="tab.href"
            class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
            :class="isCurrentUrl(tab.href) ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground'"
        >
            {{ tab.title }}
        </Link>
    </nav>
</template>
