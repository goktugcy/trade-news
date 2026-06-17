<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import AdminNav from '@/components/tradenews/AdminNav.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Stock = {
    id: number;
    symbol: string;
    name: string;
    market: string;
    sector: string | null;
    currency: string;
    aliases: string[];
    keywords: string[];
    is_active: boolean;
};

type MarketOption = { value: string; label: string; currency: string };

const props = defineProps<{
    stocks: {
        data: Stock[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: { q: string | null };
    options: { markets: MarketOption[] };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const search = ref(props.filters.q ?? '');
let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        const query: Record<string, string> = {};
        if (value) {
            query.q = value;
        }
        router.get('/admin/stocks', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

const dialogOpen = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    symbol: '',
    name: '',
    market: 'BIST',
    exchange: '',
    currency: '',
    sector: '',
    aliases: '' as string,
    keywords: '' as string,
    is_active: true,
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(stock: Stock) {
    editingId.value = stock.id;
    form.clearErrors();
    form.symbol = stock.symbol;
    form.name = stock.name;
    form.market = stock.market;
    form.exchange = '';
    form.currency = stock.currency;
    form.sector = stock.sector ?? '';
    form.aliases = stock.aliases.join(', ');
    form.keywords = stock.keywords.join(', ');
    form.is_active = stock.is_active;
    dialogOpen.value = true;
}

function submit() {
    form.transform((data) => ({
        ...data,
        aliases: data.aliases.split(',').map((s) => s.trim()).filter(Boolean),
        keywords: data.keywords.split(',').map((s) => s.trim()).filter(Boolean),
    }));

    const onSuccess = () => {
        dialogOpen.value = false;
        editingId.value = null;
        form.reset();
    };

    if (editingId.value !== null) {
        form.put('/admin/stocks/' + editingId.value, { preserveScroll: true, onSuccess });
    } else {
        form.post('/admin/stocks', { preserveScroll: true, onSuccess });
    }
}

function destroy(id: number) {
    router.delete('/admin/stocks/' + id, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Stocks" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <Input v-model="search" type="search" placeholder="Search stocks…" class="max-w-xs" />
            <Button size="sm" @click="openCreate">
                <Plus class="size-4" />
                New stock
            </Button>
        </div>

        <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 text-left text-xs uppercase tracking-wide text-muted-foreground dark:border-sidebar-border">
                            <th class="px-4 py-2 font-medium">Symbol</th>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Market</th>
                            <th class="px-4 py-2 font-medium">Sector</th>
                            <th class="px-4 py-2 font-medium">Active</th>
                            <th class="px-4 py-2 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <tr v-for="stock in stocks.data" :key="stock.id" class="hover:bg-accent">
                            <td class="px-4 py-2 font-medium text-foreground">{{ stock.symbol }}</td>
                            <td class="px-4 py-2 text-foreground">{{ stock.name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ stock.market }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ stock.sector ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="stock.is_active
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                                        : 'bg-muted text-muted-foreground'"
                                >
                                    {{ stock.is_active ? 'Yes' : 'No' }}
                                </span>
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex items-center justify-end gap-2">
                                    <Button variant="outline" size="sm" @click="openEdit(stock)">
                                        <Pencil class="size-4" />
                                        Edit
                                    </Button>
                                    <Button variant="destructive" size="sm" @click="destroy(stock.id)">
                                        <Trash2 class="size-4" />
                                        Delete
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="stocks.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-muted-foreground">No stocks found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center justify-between text-sm text-muted-foreground">
            <span>Page {{ stocks.current_page }} of {{ stocks.last_page }}</span>
            <div class="flex items-center gap-2">
                <Link
                    v-if="stocks.prev_page_url"
                    :href="stocks.prev_page_url"
                    preserve-scroll
                    class="rounded-md border border-sidebar-border/70 px-3 py-1.5 font-medium text-foreground transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    Newer
                </Link>
                <Link
                    v-if="stocks.next_page_url"
                    :href="stocks.next_page_url"
                    preserve-scroll
                    class="rounded-md border border-sidebar-border/70 px-3 py-1.5 font-medium text-foreground transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    Older
                </Link>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ editingId !== null ? 'Edit stock' : 'New stock' }}</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <Label for="symbol">Symbol</Label>
                            <Input id="symbol" v-model="form.symbol" />
                            <p v-if="form.errors.symbol" class="text-xs text-destructive">{{ form.errors.symbol }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="market">Market</Label>
                            <select
                                id="market"
                                v-model="form.market"
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option v-for="m in options.markets" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                            <p v-if="form.errors.market" class="text-xs text-destructive">{{ form.errors.market }}</p>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" />
                        <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <Label for="exchange">Exchange</Label>
                            <Input id="exchange" v-model="form.exchange" />
                            <p v-if="form.errors.exchange" class="text-xs text-destructive">{{ form.errors.exchange }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="currency">Currency</Label>
                            <Input id="currency" v-model="form.currency" />
                            <p v-if="form.errors.currency" class="text-xs text-destructive">{{ form.errors.currency }}</p>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="sector">Sector</Label>
                        <Input id="sector" v-model="form.sector" />
                        <p v-if="form.errors.sector" class="text-xs text-destructive">{{ form.errors.sector }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="aliases">Aliases <span class="text-muted-foreground">(comma-separated)</span></Label>
                        <Input id="aliases" v-model="form.aliases" />
                        <p v-if="form.errors.aliases" class="text-xs text-destructive">{{ form.errors.aliases }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="keywords">Keywords <span class="text-muted-foreground">(comma-separated)</span></Label>
                        <Input id="keywords" v-model="form.keywords" />
                        <p v-if="form.errors.keywords" class="text-xs text-destructive">{{ form.errors.keywords }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-input" />
                        Active
                    </label>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Cancel</Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ editingId !== null ? 'Save changes' : 'Create stock' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
