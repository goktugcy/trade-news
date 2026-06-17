<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Bell, Pencil, Plus, Trash2, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/tradenews/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SelectOption } from '@/types';

type Rule = {
    id: number;
    name: string;
    interval_minutes: number;
    interval_label: string;
    markets: string[] | null;
    sentiments: string[] | null;
    only_watchlist: boolean;
    min_importance: number;
    is_active: boolean;
    last_dispatched_at: string | null;
};

const props = defineProps<{
    rules: Rule[];
    options: { intervals: SelectOption[]; markets: SelectOption[]; sentiments: SelectOption[] };
    telegramConnected: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Alerts', href: '/alerts' }] },
});

const editingId = ref<number | null>(null);

const form = useForm({
    name: 'My alerts',
    interval_minutes: 60,
    markets: [] as string[],
    sentiments: [] as string[],
    only_watchlist: true,
    min_importance: 0,
    is_active: true,
});

function resetForm() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function startEdit(rule: Rule) {
    editingId.value = rule.id;
    form.name = rule.name;
    form.interval_minutes = rule.interval_minutes;
    form.markets = rule.markets ?? [];
    form.sentiments = rule.sentiments ?? [];
    form.only_watchlist = rule.only_watchlist;
    form.min_importance = rule.min_importance;
    form.is_active = rule.is_active;
}

function submit() {
    if (editingId.value) {
        form.put(`/alerts/${editingId.value}`, { preserveScroll: true, onSuccess: resetForm });
    } else {
        form.post('/alerts', { preserveScroll: true, onSuccess: resetForm });
    }
}

function destroy(rule: Rule) {
    router.delete(`/alerts/${rule.id}`, { preserveScroll: true });
}

function toggleArray(arr: string[], value: string) {
    const idx = arr.indexOf(value);
    if (idx >= 0) arr.splice(idx, 1);
    else arr.push(value);
}
</script>

<template>
    <Head title="Alerts" />

    <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-lg font-semibold text-foreground">Notification Rules</h1>
        </div>

        <div
            v-if="!telegramConnected"
            class="flex items-center gap-3 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
        >
            <TriangleAlert class="size-5 shrink-0" />
            <span>Connect your Telegram account to actually receive these alerts.
                <Link href="/settings/telegram" class="font-medium underline">Connect now →</Link>
            </span>
        </div>

        <!-- Form -->
        <form
            class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            @submit.prevent="submit"
        >
            <h2 class="text-sm font-semibold text-foreground">{{ editingId ? 'Edit rule' : 'New rule' }}</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label for="name">Rule name</Label>
                    <Input id="name" v-model="form.name" />
                    <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                </div>

                <div class="space-y-1.5">
                    <Label for="interval">Frequency</Label>
                    <select
                        id="interval"
                        v-model.number="form.interval_minutes"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/40"
                    >
                        <option v-for="i in options.intervals" :key="i.value" :value="i.value">{{ i.label }}</option>
                    </select>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label>Markets <span class="text-muted-foreground">(none = all)</span></Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="m in options.markets"
                            :key="m.value"
                            type="button"
                            class="rounded-md border px-2.5 py-1 text-sm transition-colors"
                            :class="form.markets.includes(String(m.value)) ? 'border-primary bg-primary/10 text-foreground' : 'border-sidebar-border/70 text-muted-foreground hover:bg-accent dark:border-sidebar-border'"
                            @click="toggleArray(form.markets, String(m.value))"
                        >
                            {{ m.label }}
                        </button>
                    </div>
                </div>

                <div class="space-y-2">
                    <Label>Sentiment <span class="text-muted-foreground">(none = any)</span></Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="s in options.sentiments"
                            :key="s.value"
                            type="button"
                            class="rounded-md border px-2.5 py-1 text-sm capitalize transition-colors"
                            :class="form.sentiments.includes(String(s.value)) ? 'border-primary bg-primary/10 text-foreground' : 'border-sidebar-border/70 text-muted-foreground hover:bg-accent dark:border-sidebar-border'"
                            @click="toggleArray(form.sentiments, String(s.value))"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid items-end gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label for="importance">Minimum importance: {{ form.min_importance }}</Label>
                    <input
                        id="importance"
                        v-model.number="form.min_importance"
                        type="range"
                        min="0"
                        max="100"
                        step="5"
                        class="w-full accent-primary"
                    />
                </div>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.only_watchlist" type="checkbox" class="size-4 accent-primary" />
                        Only my watchlist
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_active" type="checkbox" class="size-4 accent-primary" />
                        Active
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="form.processing">
                    <Plus v-if="!editingId" class="size-4" />
                    {{ editingId ? 'Update rule' : 'Create rule' }}
                </Button>
                <Button v-if="editingId" type="button" variant="ghost" @click="resetForm">Cancel</Button>
            </div>
        </form>

        <!-- Existing rules -->
        <EmptyState
            v-if="rules.length === 0"
            :icon="Bell"
            title="No alert rules yet"
            description="Create a rule above to start receiving Telegram alerts on your chosen schedule."
        />
        <div v-else class="flex flex-col gap-2">
            <div
                v-for="rule in rules"
                :key="rule.id"
                class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-foreground">{{ rule.name }}</span>
                        <span
                            class="rounded px-1.5 py-0.5 text-[10px] font-medium"
                            :class="rule.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-muted text-muted-foreground'"
                        >{{ rule.is_active ? 'Active' : 'Paused' }}</span>
                    </div>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ rule.interval_label }} ·
                        {{ rule.markets?.length ? rule.markets.join(', ') : 'All markets' }} ·
                        {{ rule.only_watchlist ? 'Watchlist only' : 'All stocks' }} ·
                        min importance {{ rule.min_importance }}
                    </p>
                </div>
                <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground" @click="startEdit(rule)">
                    <Pencil class="size-4" />
                </button>
                <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-destructive/10 hover:text-destructive" @click="destroy(rule)">
                    <Trash2 class="size-4" />
                </button>
            </div>
        </div>
    </div>
</template>
