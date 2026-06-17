<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Copy, Send } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

defineProps<{
    integration: {
        is_connected: boolean;
        is_enabled: boolean;
        telegram_username: string | null;
        connection_code: string | null;
        code_expires_at: string | null;
        connected_at: string | null;
    } | null;
    bot: { username: string | null; configured: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Telegram', href: '/settings/telegram' }] },
});

const copied = ref(false);

function generateCode() {
    router.post('/settings/telegram/code', {}, { preserveScroll: true });
}

function toggle() {
    router.post('/settings/telegram/toggle', {}, { preserveScroll: true });
}

const disconnectForm = useForm({});
function disconnect() {
    disconnectForm.delete('/settings/telegram', { preserveScroll: true });
}

function copyCode(code: string) {
    navigator.clipboard?.writeText(code);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}
</script>

<template>
    <Head title="Telegram settings" />

    <h1 class="sr-only">Telegram settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Telegram integration"
            description="Connect Telegram to receive your stock news alerts in chat."
        />

        <div
            v-if="!bot.configured"
            class="rounded-lg border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
        >
            The Telegram bot is not configured on the server yet (missing
            <code class="rounded bg-black/5 px-1 dark:bg-white/10">TELEGRAM_BOT_TOKEN</code>).
            You can still generate a code; delivery starts once the bot is set up.
        </div>

        <!-- Connected state -->
        <div
            v-if="integration?.is_connected"
            class="rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
        >
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300">
                    <Check class="size-5" />
                </div>
                <div>
                    <p class="font-medium text-foreground">Telegram connected</p>
                    <p class="text-sm text-muted-foreground">
                        <span v-if="integration.telegram_username">@{{ integration.telegram_username }} · </span>
                        Alerts are {{ integration.is_enabled ? 'enabled' : 'paused' }}.
                    </p>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-2">
                <Button :variant="integration.is_enabled ? 'outline' : 'default'" @click="toggle">
                    {{ integration.is_enabled ? 'Pause alerts' : 'Enable alerts' }}
                </Button>
                <Button variant="ghost" class="text-destructive hover:text-destructive" @click="disconnect">
                    Disconnect
                </Button>
            </div>
        </div>

        <!-- Connection flow -->
        <div v-else class="rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border">
            <ol class="space-y-4 text-sm">
                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">1</span>
                    <div class="flex-1">
                        <p class="font-medium text-foreground">Generate a one-time connection code</p>
                        <div v-if="integration?.connection_code" class="mt-2 flex items-center gap-2">
                            <code class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-1.5 font-mono text-base tracking-widest text-foreground dark:border-sidebar-border">
                                {{ integration.connection_code }}
                            </code>
                            <Button variant="outline" size="sm" @click="copyCode(integration.connection_code)">
                                <component :is="copied ? Check : Copy" class="size-4" />
                                {{ copied ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                        <Button v-else class="mt-2" size="sm" @click="generateCode">Generate code</Button>
                        <Button v-if="integration?.connection_code" variant="ghost" size="sm" class="mt-2 ml-1" @click="generateCode">
                            Regenerate
                        </Button>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">2</span>
                    <div class="flex-1">
                        <p class="font-medium text-foreground">Open the bot and send the code</p>
                        <p class="text-muted-foreground">
                            Message
                            <a
                                v-if="bot.username"
                                :href="`https://t.me/${bot.username}`"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 font-medium text-foreground hover:underline"
                            >
                                <Send class="size-3" /> @{{ bot.username }}
                            </a>
                            <span v-else>the TradeNews bot</span>
                            with <code class="rounded bg-black/5 px-1 dark:bg-white/10">/start &lt;code&gt;</code> (or just paste the code).
                        </p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">3</span>
                    <div class="flex-1">
                        <p class="font-medium text-foreground">You're connected</p>
                        <p class="text-muted-foreground">This page updates automatically once the bot links your chat. Refresh if needed.</p>
                    </div>
                </li>
            </ol>
        </div>
    </div>
</template>
