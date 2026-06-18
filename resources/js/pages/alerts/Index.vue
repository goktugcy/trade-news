<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Bell, Pencil, Plus, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
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

type StockAlert = {
    id: number;
    stock_id: number;
    symbol: string | null;
    type: string;
    type_label: string;
    threshold: number | null;
    cooldown_minutes: number;
    is_active: boolean;
    notify_in_app: boolean;
    notify_telegram: boolean;
    last_triggered_at: string | null;
};

type AlertTypeOption = { value: string; label: string; needs_threshold: boolean; unit: string | null };

const props = defineProps<{
    rules: Rule[];
    options: { intervals: SelectOption[]; markets: SelectOption[]; sentiments: SelectOption[] };
    stockAlerts: StockAlert[];
    alertTypes: AlertTypeOption[];
    watchlistStocks: { id: number; symbol: string; name: string }[];
    telegramConnected: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Alerts', href: '/alerts' }] },
});

const tab = ref<'stock' | 'news'>('stock');

// ---------------- News rules (existing) ----------------
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

// ---------------- Stock alerts (new) ----------------
const editingStockId = ref<number | null>(null);
const stockForm = useForm({
    stock_id: props.watchlistStocks[0]?.id ?? null,
    type: 'price_above',
    threshold: null as number | null,
    cooldown_minutes: 60,
    notify_in_app: true,
    notify_telegram: false,
    is_active: true,
});

const selectedType = computed<AlertTypeOption | undefined>(() =>
    props.alertTypes.find((t: AlertTypeOption) => t.value === stockForm.type),
);

function resetStockForm() {
    editingStockId.value = null;
    stockForm.reset();
    stockForm.stock_id = props.watchlistStocks[0]?.id ?? null;
    stockForm.clearErrors();
}
function startEditStock(a: StockAlert) {
    editingStockId.value = a.id;
    stockForm.stock_id = a.stock_id;
    stockForm.type = a.type;
    stockForm.threshold = a.threshold;
    stockForm.cooldown_minutes = a.cooldown_minutes;
    stockForm.notify_in_app = a.notify_in_app;
    stockForm.notify_telegram = a.notify_telegram;
    stockForm.is_active = a.is_active;
}
function submitStock() {
    if (editingStockId.value) {
        stockForm.put(`/alerts/stock/${editingStockId.value}`, { preserveScroll: true, onSuccess: resetStockForm });
    } else {
        stockForm.post('/alerts/stock', { preserveScroll: true, onSuccess: resetStockForm });
    }
}
function destroyStock(a: StockAlert) {
    router.delete(`/alerts/stock/${a.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Alerts" />

    <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-lg font-semibold text-foreground">Alerts</h1>

        <div
            v-if="!telegramConnected"
            class="flex items-center gap-3 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
        >
            <TriangleAlert class="size-5 shrink-0" />
            <span>Connect Telegram to also receive alerts in chat (in-app notifications work regardless).
                <Link href="/settings/telegram" class="font-medium underline">Connect →</Link>
            </span>
        </div>

        <!-- Tabs -->
        <div class="inline-flex w-fit rounded-lg bg-muted p-0.5">
            <button
                type="button"
                class="rounded-md px-3 py-1 text-sm font-medium transition-colors"
                :class="tab === 'stock' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                @click="tab = 'stock'"
            >Price &amp; volume</button>
            <button
                type="button"
                class="rounded-md px-3 py-1 text-sm font-medium transition-colors"
                :class="tab === 'news' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                @click="tab = 'news'"
            >News rules</button>
        </div>

        <!-- ============ STOCK ALERTS TAB ============ -->
        <div v-show="tab === 'stock'" class="flex flex-col gap-4">
            <EmptyState
                v-if="watchlistStocks.length === 0"
                title="Add stocks to your watchlist first"
                description="Condition alerts target a stock you follow."
            >
                <Link href="/stocks" class="text-sm font-medium text-foreground hover:underline">Browse stocks →</Link>
            </EmptyState>

            <form
                v-else
                class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
                @submit.prevent="submitStock"
            >
                <h2 class="text-sm font-semibold text-foreground">{{ editingStockId ? 'Edit alert' : 'New price / volume / news alert' }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="stock">Stock</Label>
                        <select id="stock" v-model.number="stockForm.stock_id" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option v-for="s in watchlistStocks" :key="s.id" :value="s.id">{{ s.symbol }} — {{ s.name }}</option>
                        </select>
                        <p v-if="stockForm.errors.stock_id" class="text-xs text-destructive">{{ stockForm.errors.stock_id }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="atype">Condition</Label>
                        <select id="atype" v-model="stockForm.type" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option v-for="t in alertTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-if="selectedType?.needs_threshold" class="space-y-1.5">
                        <Label for="threshold">Threshold <span class="text-muted-foreground">({{ selectedType?.unit }})</span></Label>
                        <input
                            id="threshold"
                            v-model.number="stockForm.threshold"
                            type="number"
                            step="any"
                            min="0"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/40"
                        />
                        <p v-if="stockForm.errors.threshold" class="text-xs text-destructive">{{ stockForm.errors.threshold }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="cooldown">Cooldown (minutes)</Label>
                        <Input id="cooldown" v-model.number="stockForm.cooldown_minutes" type="number" min="0" max="1440" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="stockForm.notify_in_app" type="checkbox" class="size-4 accent-primary" /> In-app
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="stockForm.notify_telegram" type="checkbox" class="size-4 accent-primary" /> Telegram
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="stockForm.is_active" type="checkbox" class="size-4 accent-primary" /> Active
                    </label>
                </div>

                <div class="flex items-center gap-2">
                    <Button type="submit" :disabled="stockForm.processing">
                        <Plus v-if="!editingStockId" class="size-4" /> {{ editingStockId ? 'Update alert' : 'Create alert' }}
                    </Button>
                    <Button v-if="editingStockId" type="button" variant="ghost" @click="resetStockForm">Cancel</Button>
                </div>
            </form>

            <EmptyState
                v-if="stockAlerts.length === 0 && watchlistStocks.length > 0"
                :icon="Bell"
                title="No price/volume alerts yet"
                description="Create one above — you'll get an in-app notification (and Telegram if enabled) when it triggers."
            />
            <div v-else-if="stockAlerts.length" class="flex flex-col gap-2">
                <div
                    v-for="a in stockAlerts"
                    :key="a.id"
                    class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-foreground">{{ a.symbol }}</span>
                            <span class="text-sm text-muted-foreground">{{ a.type_label }}<template v-if="a.threshold !== null"> {{ a.threshold }}</template></span>
                            <span
                                class="rounded px-1.5 py-0.5 text-[10px] font-medium"
                                :class="a.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-muted text-muted-foreground'"
                            >{{ a.is_active ? 'Active' : 'Paused' }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Cooldown {{ a.cooldown_minutes }}m ·
                            {{ [a.notify_in_app ? 'In-app' : null, a.notify_telegram ? 'Telegram' : null].filter(Boolean).join(' + ') || 'No channel' }}
                            <template v-if="a.last_triggered_at"> · last {{ a.last_triggered_at }}</template>
                        </p>
                    </div>
                    <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground" @click="startEditStock(a)">
                        <Pencil class="size-4" />
                    </button>
                    <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-destructive/10 hover:text-destructive" @click="destroyStock(a)">
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- ============ NEWS RULES TAB ============ -->
        <div v-show="tab === 'news'" class="flex flex-col gap-4">
            <form
                class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
                @submit.prevent="submit"
            >
                <h2 class="text-sm font-semibold text-foreground">{{ editingId ? 'Edit rule' : 'New news rule' }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="name">Rule name</Label>
                        <Input id="name" v-model="form.name" />
                        <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="interval">Frequency</Label>
                        <select id="interval" v-model.number="form.interval_minutes" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm">
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
                            >{{ m.label }}</button>
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
                            >{{ s.label }}</button>
                        </div>
                    </div>
                </div>

                <div class="grid items-end gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="importance">Minimum importance: {{ form.min_importance }}</Label>
                        <input id="importance" v-model.number="form.min_importance" type="range" min="0" max="100" step="5" class="w-full accent-primary" />
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.only_watchlist" type="checkbox" class="size-4 accent-primary" /> Only my watchlist
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.is_active" type="checkbox" class="size-4 accent-primary" /> Active
                        </label>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button type="submit" :disabled="form.processing">
                        <Plus v-if="!editingId" class="size-4" /> {{ editingId ? 'Update rule' : 'Create rule' }}
                    </Button>
                    <Button v-if="editingId" type="button" variant="ghost" @click="resetForm">Cancel</Button>
                </div>
            </form>

            <EmptyState
                v-if="rules.length === 0"
                :icon="Bell"
                title="No news rules yet"
                description="Create a rule to receive scheduled news digests for your markets and watchlist."
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
    </div>
</template>
