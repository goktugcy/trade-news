<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, RotateCcw, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import AdminNav from '@/components/tradenews/AdminNav.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Provider = {
    id: number;
    key: string;
    name: string;
    type: string;
    markets: string[];
    capabilities: string[];
    status: string;
    status_color: string;
    is_active: boolean;
    auto_sync_stocks: boolean;
    auto_recovery: boolean;
    api_key_configured: boolean;
    consecutive_failures: number;
    priority: number;
    refresh_interval_minutes: number;
    fetch_limit: number;
    base_url: string | null;
    last_latency_ms: number | null;
    avg_latency_ms: number | null;
    daily_request_count: number;
    daily_failure_count: number;
    rate_limited: boolean;
    last_error: string | null;
    last_checked_at: string | null;
    last_fetched_at: string | null;
};

type Option = { value: string; label: string };

const props = defineProps<{
    providers: Provider[];
    statuses: Option[];
    types: Option[];
    marketOptions: Option[];
    capabilityOptions: string[];
    synthetic_counts: { prices: number; news: number };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const dotColors: Record<string, string> = {
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
    rose: 'bg-rose-500',
    slate: 'bg-slate-400',
};
const dotClass = (c: string): string => dotColors[c] ?? 'bg-slate-400';

const open = ref(false);
const editingId = ref<number | null>(null);
const editingApiKeyConfigured = ref(false);

const form = useForm({
    key: '',
    name: '',
    type: props.types[0]?.value ?? 'market_data',
    base_url: '',
    api_key: '',
    clear_api_key: false,
    markets: [] as string[],
    capabilities: [] as string[],
    priority: 100,
    refresh_interval_minutes: 5,
    fetch_limit: 50,
    auto_sync_stocks: false,
    auto_recovery: true,
    is_active: true,
});

function toggle(arr: string[], value: string) {
    const i = arr.indexOf(value);
    if (i >= 0) arr.splice(i, 1);
    else arr.push(value);
}

function openCreate() {
    editingId.value = null;
    editingApiKeyConfigured.value = false;
    form.reset();
    form.clearErrors();
    open.value = true;
}

function openEdit(p: Provider) {
    editingId.value = p.id;
    editingApiKeyConfigured.value = p.api_key_configured;
    form.clearErrors();
    form.key = p.key;
    form.name = p.name;
    form.type = p.type;
    form.base_url = p.base_url ?? '';
    form.api_key = '';
    form.clear_api_key = false;
    form.markets = [...p.markets];
    form.capabilities = [...p.capabilities];
    form.priority = p.priority;
    form.refresh_interval_minutes = p.refresh_interval_minutes;
    form.fetch_limit = p.fetch_limit;
    form.auto_sync_stocks = p.auto_sync_stocks;
    form.auto_recovery = p.auto_recovery;
    form.is_active = p.is_active;
    open.value = true;
}

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editingId.value) {
        form.put(`/admin/providers/${editingId.value}`, opts);
    } else {
        form.post('/admin/providers', opts);
    }
}

function destroy(p: Provider) {
    if (confirm(`Delete provider "${p.name}"?`)) {
        router.delete(`/admin/providers/${p.id}`, { preserveScroll: true });
    }
}

function purgeSynthetic() {
    router.delete('/admin/providers/synthetic-data', { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · API Providers" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <div class="flex items-center justify-between">
            <h1 class="text-lg font-semibold text-foreground">API Providers</h1>
            <Button size="sm" @click="openCreate"><Plus class="size-4" /> New provider</Button>
        </div>

        <!-- Synthetic purge -->
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-foreground">Synthetic data</h2>
                <p class="mt-1 text-xs text-muted-foreground">{{ synthetic_counts.prices }} price rows · {{ synthetic_counts.news }} news rows</p>
            </div>
            <Button size="sm" variant="destructive" :disabled="synthetic_counts.prices + synthetic_counts.news === 0" @click="purgeSynthetic">Purge</Button>
        </div>

        <!-- Provider cards -->
        <div
            v-for="p in providers"
            :key="p.id"
            class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="size-2 rounded-full" :class="dotClass(p.status_color)" />
                        <h2 class="truncate text-sm font-semibold text-foreground">{{ p.name }}</h2>
                        <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] capitalize text-muted-foreground">{{ p.status }}</span>
                        <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">{{ p.type }}</span>
                        <span v-if="p.api_key_configured" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">key set</span>
                        <span v-if="p.auto_sync_stocks" class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">stock auto-sync</span>
                        <span v-if="!p.is_active" class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">disabled</span>
                    </div>
                    <p v-if="p.base_url" class="mt-1 max-w-md truncate text-xs text-muted-foreground">{{ p.base_url }}</p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <span v-for="m in p.markets" :key="m" class="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">{{ m }}</span>
                        <span v-for="c in p.capabilities" :key="c" class="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] text-violet-700 dark:bg-violet-500/15 dark:text-violet-300">{{ c }}</span>
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                        <span>prio {{ p.priority }}</span>
                        <span>{{ p.refresh_interval_minutes }}m · limit {{ p.fetch_limit }}</span>
                        <span class="tabular-nums">{{ p.last_latency_ms !== null ? p.last_latency_ms + ' ms' : '— ms' }}</span>
                        <span v-if="p.avg_latency_ms !== null" class="tabular-nums">avg {{ p.avg_latency_ms }} ms</span>
                        <span class="tabular-nums">today {{ p.daily_request_count }} req<span v-if="p.daily_failure_count > 0">, {{ p.daily_failure_count }} fail</span></span>
                        <span v-if="p.consecutive_failures > 0" class="text-rose-600 dark:text-rose-400">{{ p.consecutive_failures }} fails</span>
                        <span v-if="p.rate_limited" class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">rate-limited</span>
                        <span v-if="p.auto_recovery">auto-recovery</span>
                        <span v-if="p.last_checked_at">checked {{ p.last_checked_at }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground" title="Edit" @click="openEdit(p)">
                        <Pencil class="size-4" />
                    </button>
                    <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-destructive/10 hover:text-destructive" title="Delete" @click="destroy(p)">
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>
            <p v-if="p.last_error" class="mt-3 break-all text-xs text-rose-600 dark:text-rose-400">{{ p.last_error }}</p>
        </div>

        <div v-if="providers.length === 0" class="rounded-xl border border-sidebar-border/70 bg-card px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border">
            No API providers configured.
        </div>

        <!-- Create / edit dialog -->
        <Dialog v-model:open="open">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ editingId ? 'Edit provider' : 'New provider' }}</DialogTitle>
                </DialogHeader>
                <form class="flex flex-col gap-3" @submit.prevent="submit">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="p-key">Key</Label>
                            <Input id="p-key" v-model="form.key" :disabled="editingId !== null" placeholder="finnhub" />
                            <p v-if="form.errors.key" class="text-xs text-destructive">{{ form.errors.key }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label for="p-name">Name</Label>
                            <Input id="p-name" v-model="form.name" placeholder="Finnhub" />
                            <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="p-type">Type</Label>
                            <select id="p-type" v-model="form.type" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm">
                                <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="p-url">Base URL</Label>
                            <Input id="p-url" v-model="form.base_url" placeholder="https://…" />
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label for="p-api-key">API key</Label>
                        <Input id="p-api-key" v-model="form.api_key" type="password" autocomplete="new-password" placeholder="Leave empty to keep saved key" :disabled="form.clear_api_key" />
                        <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            <span v-if="editingId && editingApiKeyConfigured">Saved key is configured.</span>
                            <span v-else-if="editingId">No key is saved.</span>
                            <label v-if="editingId && editingApiKeyConfigured" class="flex items-center gap-1.5 text-rose-600 dark:text-rose-400">
                                <input v-model="form.clear_api_key" type="checkbox" class="size-3.5 accent-destructive" @change="form.api_key = form.clear_api_key ? '' : form.api_key" />
                                Clear saved key
                            </label>
                        </div>
                        <p v-if="form.errors.api_key" class="text-xs text-destructive">{{ form.errors.api_key }}</p>
                    </div>

                    <div class="space-y-1">
                        <Label>Markets <span class="text-muted-foreground">(none = all)</span></Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="m in marketOptions"
                                :key="m.value"
                                type="button"
                                class="rounded-md border px-2.5 py-1 text-sm transition-colors"
                                :class="form.markets.includes(m.value) ? 'border-primary bg-primary/10 text-foreground' : 'border-sidebar-border/70 text-muted-foreground hover:bg-accent dark:border-sidebar-border'"
                                @click="toggle(form.markets, m.value)"
                            >{{ m.label }}</button>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label>Capabilities</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="c in capabilityOptions"
                                :key="c"
                                type="button"
                                class="rounded-md border px-2.5 py-1 text-sm transition-colors"
                                :class="form.capabilities.includes(c) ? 'border-primary bg-primary/10 text-foreground' : 'border-sidebar-border/70 text-muted-foreground hover:bg-accent dark:border-sidebar-border'"
                                @click="toggle(form.capabilities, c)"
                            >{{ c }}</button>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label for="p-prio">Priority</Label>
                            <Input id="p-prio" v-model.number="form.priority" type="number" min="1" max="999" />
                        </div>
                        <div class="space-y-1">
                            <Label for="p-refresh">Refresh (min)</Label>
                            <Input id="p-refresh" v-model.number="form.refresh_interval_minutes" type="number" min="1" max="1440" />
                        </div>
                        <div class="space-y-1">
                            <Label for="p-limit">Fetch limit</Label>
                            <Input id="p-limit" v-model.number="form.fetch_limit" type="number" min="1" max="5000" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.auto_sync_stocks" type="checkbox" class="size-4 accent-primary" /> Auto-sync market stocks</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.auto_recovery" type="checkbox" class="size-4 accent-primary" /> Auto-recovery</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="size-4 accent-primary" /> Active</label>
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2">
                        <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                        <Button type="submit" :disabled="form.processing">
                            <RotateCcw v-if="editingId" class="size-4" /><Plus v-else class="size-4" />
                            {{ editingId ? 'Save' : 'Create' }}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
