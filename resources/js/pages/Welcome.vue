<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Bell,
    LineChart,
    Newspaper,
    Send,
    Star,
    TrendingUp,
} from '@lucide/vue';
import { dashboard, login, register } from '@/routes';

const features = [
    {
        icon: Newspaper,
        title: 'Unified news feed',
        text: 'NASDAQ market news, deduplicated and matched to the right tickers.',
    },
    {
        icon: LineChart,
        title: 'Prices & charts',
        text: 'Current quotes and historical candlestick charts, fetched centrally and cached.',
    },
    {
        icon: Star,
        title: 'Watchlists',
        text: 'Follow the stocks you care about and filter news down to your portfolio.',
    },
    {
        icon: Bell,
        title: 'Smart alerts',
        text: 'Rules by market, sentiment and importance — on your own schedule.',
    },
    {
        icon: Send,
        title: 'Telegram delivery',
        text: 'Link your Telegram and receive alerts straight to chat.',
    },
    {
        icon: TrendingUp,
        title: 'Sentiment & impact',
        text: 'Each headline scored for sentiment and importance automatically.',
    },
];
</script>

<template>
    <Head title="TradeNews — Market news & stock tracking" />

    <div class="min-h-screen bg-background text-foreground">
        <!-- Header -->
        <header
            class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5"
        >
            <div class="flex items-center gap-2">
                <div
                    class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                >
                    <TrendingUp class="size-5" />
                </div>
                <span class="text-lg font-semibold">TradeNews</span>
            </div>
            <nav class="flex items-center gap-2 text-sm">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                >
                    Open dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-md px-4 py-2 font-medium text-foreground hover:bg-accent"
                        >Log in</Link
                    >
                    <Link
                        :href="register()"
                        class="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                        >Get started</Link
                    >
                </template>
            </nav>
        </header>

        <!-- Hero -->
        <section
            class="mx-auto max-w-6xl px-6 pt-12 pb-16 text-center sm:pt-20"
        >
            <span
                class="inline-flex items-center gap-2 rounded-full border border-sidebar-border/70 bg-card px-3 py-1 text-xs font-medium text-muted-foreground dark:border-sidebar-border"
            >
                <span class="size-1.5 rounded-full bg-emerald-500" /> NASDAQ ·
                real-time-ish feed
            </span>
            <h1
                class="mx-auto mt-6 max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl"
            >
                A financial terminal that reads like a clean social feed.
            </h1>
            <p class="mx-auto mt-4 max-w-2xl text-lg text-muted-foreground">
                Track stocks, follow market &amp; company news, and get Telegram
                alerts on the schedule you choose. Data is fetched centrally and
                served fast — no per-user API hammering.
            </p>
            <div class="mt-8 flex items-center justify-center gap-3">
                <Link
                    :href="$page.props.auth.user ? dashboard() : register()"
                    class="rounded-lg bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90"
                >
                    {{
                        $page.props.auth.user
                            ? 'Go to dashboard'
                            : 'Create free account'
                    }}
                </Link>
                <Link
                    v-if="!$page.props.auth.user"
                    :href="login()"
                    class="rounded-lg border border-sidebar-border/70 px-6 py-3 text-sm font-semibold hover:bg-accent dark:border-sidebar-border"
                >
                    Log in
                </Link>
            </div>
        </section>

        <!-- Features -->
        <section class="mx-auto max-w-6xl px-6 pb-24">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <component :is="feature.icon" class="size-5" />
                    </div>
                    <h3 class="mt-4 font-semibold">{{ feature.title }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ feature.text }}
                    </p>
                </div>
            </div>
        </section>

        <footer
            class="border-t border-sidebar-border/70 py-6 text-center text-sm text-muted-foreground dark:border-sidebar-border"
        >
            Built with Laravel, Inertia &amp; Vue · TradeNews
        </footer>
    </div>
</template>
