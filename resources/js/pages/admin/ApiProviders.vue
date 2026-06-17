<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminNav from '@/components/tradenews/AdminNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Provider = {
    id: number;
    key: string;
    name: string;
    type: string;
    status: string;
    status_color: string;
    is_active: boolean;
    priority: number;
    base_url: string | null;
    last_latency_ms: number | null;
    last_error: string | null;
    last_checked_at: string | null;
};

type StatusOption = { value: string; label: string };

const props = defineProps<{
    providers: Provider[];
    statuses: StatusOption[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const rows = ref<Provider[]>(props.providers.map((p) => ({ ...p })));

const dotColors: Record<string, string> = {
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
    rose: 'bg-rose-500',
    slate: 'bg-slate-400',
};

function dotClass(color: string): string {
    return dotColors[color] ?? 'bg-slate-400';
}

function save(row: Provider) {
    router.put(
        '/admin/providers/' + row.id,
        { is_active: row.is_active, priority: row.priority, status: row.status },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Admin · API Providers" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <div class="grid grid-cols-1 gap-3">
            <div
                v-for="row in rows"
                :key="row.id"
                class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full" :class="dotClass(row.status_color)" />
                            <h2 class="truncate text-sm font-semibold text-foreground">{{ row.name }}</h2>
                            <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ row.type }}</span>
                        </div>
                        <p v-if="row.base_url" class="mt-1 max-w-md truncate text-xs text-muted-foreground">{{ row.base_url }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            <span class="tabular-nums">{{ row.last_latency_ms !== null ? row.last_latency_ms + ' ms' : '— ms' }}</span>
                            <span v-if="row.last_checked_at">Checked {{ row.last_checked_at }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-end gap-3">
                        <div class="space-y-1.5">
                            <Label :for="'status-' + row.id" class="text-xs">Status</Label>
                            <select
                                :id="'status-' + row.id"
                                v-model="row.status"
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <Label :for="'priority-' + row.id" class="text-xs">Priority</Label>
                            <Input :id="'priority-' + row.id" v-model="row.priority" type="number" class="w-24" />
                        </div>
                        <label class="flex h-9 items-center gap-2 text-sm text-foreground">
                            <input v-model="row.is_active" type="checkbox" class="size-4 rounded border-input" />
                            Active
                        </label>
                        <Button size="sm" @click="save(row)">Save</Button>
                    </div>
                </div>

                <p v-if="row.last_error" class="mt-3 break-all text-xs text-rose-600 dark:text-rose-400">
                    {{ row.last_error }}
                </p>
            </div>

            <div
                v-if="rows.length === 0"
                class="rounded-xl border border-sidebar-border/70 bg-card px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
            >
                No API providers configured.
            </div>
        </div>
    </div>
</template>
