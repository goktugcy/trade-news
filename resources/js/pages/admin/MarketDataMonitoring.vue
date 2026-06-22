<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminNav from '@/components/tradenews/AdminNav.vue';

type Provider = {
    key: string;
    name: string;
    status: string;
    status_color: string;
    is_active: boolean;
    markets: string[];
    capabilities: string[];
    daily_request_count: number;
    daily_failure_count: number;
    avg_latency_ms: number | null;
    last_latency_ms: number | null;
    rate_limited: boolean;
    consecutive_failures: number;
    last_error: string | null;
    last_checked_at: string | null;
};

type Freshness = { type: string; status: string | null; finished_at: string | null; processed: number | null };

defineProps<{
    providers: Provider[];
    freshness: Freshness[];
    quote_freshness: string | null;
    index_counts: { nasdaq100: number; sp500: number; active_stocks: number };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const statusClass = (c: string): string =>
    ({
        emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        rose: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        slate: 'bg-muted text-muted-foreground',
    })[c] ?? 'bg-muted text-muted-foreground';

const typeLabel = (t: string): string =>
    ({ nasdaq_list: 'Index constituents', company_profiles: 'Company profiles', bist100_quotes: 'BIST quotes' })[t] ?? t;
</script>

<template>
    <Head title="Admin · Market Data" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <AdminNav />
        <h1 class="text-lg font-semibold text-foreground">Market data monitoring</h1>

        <!-- Index sizes + quote freshness -->
        <div class="grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <p class="text-xs text-muted-foreground">NASDAQ-100</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-foreground">{{ index_counts.nasdaq100 }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <p class="text-xs text-muted-foreground">S&P 500</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-foreground">{{ index_counts.sp500 }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <p class="text-xs text-muted-foreground">Active stocks</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-foreground">{{ index_counts.active_stocks }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <p class="text-xs text-muted-foreground">Last quote stored</p>
                <p class="mt-1 text-sm font-medium text-foreground">{{ quote_freshness ?? 'never' }}</p>
            </div>
        </div>

        <!-- Sync freshness -->
        <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <h2 class="mb-3 text-sm font-semibold text-foreground">Sync freshness</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <div v-for="f in freshness" :key="f.type" class="rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                    <p class="text-sm font-medium text-foreground">{{ typeLabel(f.type) }}</p>
                    <p class="mt-1 text-xs" :class="f.status === 'failed' ? 'text-rose-600 dark:text-rose-400' : 'text-muted-foreground'">
                        {{ f.status ?? 'never run' }}<span v-if="f.finished_at"> · {{ f.finished_at }}</span>
                    </p>
                    <p v-if="f.processed !== null" class="text-xs text-muted-foreground">{{ f.processed }} processed</p>
                </div>
            </div>
        </div>

        <!-- Provider usage -->
        <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <table class="w-full text-sm">
                <thead class="border-b border-sidebar-border/70 text-left text-xs uppercase tracking-wide text-muted-foreground dark:border-sidebar-border">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Provider</th>
                        <th class="px-4 py-2.5 font-medium">Status</th>
                        <th class="px-4 py-2.5 text-right font-medium">Today (req / fail)</th>
                        <th class="px-4 py-2.5 text-right font-medium">Latency (last / avg)</th>
                        <th class="px-4 py-2.5 font-medium">Checked</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <tr v-for="p in providers" :key="p.key">
                        <td class="px-4 py-2.5">
                            <span class="font-medium text-foreground">{{ p.name }}</span>
                            <span class="ml-1 text-xs text-muted-foreground">{{ (p.markets.join(', ')) || 'all markets' }}</span>
                            <span v-if="!p.is_active" class="ml-2 rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">disabled</span>
                            <span v-if="p.rate_limited" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">rate-limited</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" :class="statusClass(p.status_color)">{{ p.status }}</span>
                            <span v-if="p.consecutive_failures > 0" class="ml-2 text-xs text-rose-600 dark:text-rose-400">{{ p.consecutive_failures }} fails</span>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ p.daily_request_count }} / {{ p.daily_failure_count }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">
                            {{ p.last_latency_ms !== null ? p.last_latency_ms + ' ms' : '—' }} / {{ p.avg_latency_ms !== null ? p.avg_latency_ms + ' ms' : '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-muted-foreground">{{ p.last_checked_at ?? '—' }}</td>
                    </tr>
                    <tr v-if="providers.length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">No market-data providers configured.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
