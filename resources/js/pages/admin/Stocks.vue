<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Clock3,
    Database,
    DownloadCloud,
    FileText,
    Loader2,
    Pencil,
    Plus,
    Trash2,
    Upload,
    UploadCloud,
    XCircle,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    destroy as destroyStock,
    index as stockIndex,
    store as storeStock,
    update as updateStock,
} from '@/actions/App/Http/Controllers/Admin/AdminStockController';
import {
    fetchStooq as fetchStockStooq,
    store as importHistoricalPrices,
} from '@/actions/App/Http/Controllers/Admin/AdminStockHistoricalPriceController';
import {
    fetchAll as fetchAllStooq,
    store as importStooqHistoricalPrices,
} from '@/actions/App/Http/Controllers/Admin/AdminStooqHistoricalPriceController';
import AdminNav from '@/components/tradenews/AdminNav.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
type TimeframeOption = { value: string; label: string };
type ImportResult = {
    source: string;
    processed: number;
    imported: number;
    created: number;
    updated: number;
    skipped: number;
    stocks_created: number;
    errors: string[];
};
type ImportQueueStatus = 'queued' | 'running' | 'done' | 'failed';
type ImportQueueItem = {
    id: string;
    file: File;
    status: ImportQueueStatus;
    progress: number;
    result: ImportResult | null;
    error: string | null;
};

const props = defineProps<{
    stocks: {
        data: Stock[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: { q: string | null };
    options: { markets: MarketOption[]; timeframes: TimeframeOption[] };
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
        router.get(
            stockIndex.url({ query }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300);
});

const dialogOpen = ref(false);
const bulkImportOpen = ref(false);
const stooqFetchingAll = ref(false);
const stooqFetchingStock = ref(false);
const editingId = ref<number | null>(null);
const editingStock = ref<Stock | null>(null);
const manualFileInput = ref<HTMLInputElement | null>(null);
const stooqFileInput = ref<HTMLInputElement | null>(null);
const manualFileName = ref('');
const manualDropActive = ref(false);
const stooqDropActive = ref(false);
const importResult = ref<ImportResult | null>(null);
const importQueue = ref<ImportQueueItem[]>([]);
const importQueueRunning = ref(false);
const activeQueueItemId = ref<string | null>(null);
let removeFlashListener: (() => void) | null = null;
let importQueueId = 0;

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

const historicalForm = useForm({
    file: null as File | null,
    timeframe: props.options.timeframes[0]?.value ?? '1d',
});

const stooqForm = useForm({
    files: [] as File[],
    fallback_market: 'ALL',
});

const latestImportTitle = computed(() => {
    if (!importResult.value) {
        return null;
    }

    if (importResult.value.source === 'bulk-upload') {
        return 'Last bulk import';
    }

    if (importResult.value.source === 'stooq-upload') {
        return 'Last Stooq import';
    }

    return importResult.value.source === 'bulk-csv'
        ? 'Last bulk CSV import'
        : 'Last CSV import';
});

const stooqFileLabel = computed(() => {
    if (importQueue.value.length === 0) {
        return 'Drop files here or click to browse';
    }

    if (importQueue.value.length === 1) {
        return importQueue.value[0].file.name;
    }

    return `${importQueue.value.length} files selected`;
});

const stooqFilePreview = computed(() =>
    importQueue.value
        .slice(0, 4)
        .map((item) => item.file.name)
        .join(', '),
);

const stooqFileError = computed(() => {
    const errors = stooqForm.errors as Record<string, string | undefined>;

    return (
        errors.files ??
        errors.file ??
        Object.entries(errors).find(([key]) => key.startsWith('files.'))?.[1] ??
        null
    );
});

const importQueueCompletedCount = computed(
    () =>
        importQueue.value.filter(
            (item) => item.status === 'done' || item.status === 'failed',
        ).length,
);
const importQueueSuccessCount = computed(
    () => importQueue.value.filter((item) => item.status === 'done').length,
);
const importQueueFailedCount = computed(
    () => importQueue.value.filter((item) => item.status === 'failed').length,
);
const importQueueProgress = computed(() => {
    if (importQueue.value.length === 0) {
        return 0;
    }

    return Math.round(
        (importQueueCompletedCount.value / importQueue.value.length) * 100,
    );
});

onMounted(() => {
    removeFlashListener = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const result = flash?.stock_import as ImportResult | undefined;

        if (result) {
            importResult.value = result;

            const activeItem = importQueue.value.find(
                (item) => item.id === activeQueueItemId.value,
            );

            if (activeItem) {
                activeItem.result = result;
            }
        }
    });
});

onBeforeUnmount(() => {
    removeFlashListener?.();
});

function openCreate() {
    editingId.value = null;
    editingStock.value = null;
    form.reset();
    form.clearErrors();
    resetHistoricalUpload();
    dialogOpen.value = true;
}

function openEdit(stock: Stock) {
    editingId.value = stock.id;
    editingStock.value = stock;
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
    resetHistoricalUpload();
    dialogOpen.value = true;
}

function submit() {
    form.transform((data) => ({
        ...data,
        aliases: data.aliases
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean),
        keywords: data.keywords
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean),
    }));

    const onSuccess = () => {
        dialogOpen.value = false;
        editingId.value = null;
        editingStock.value = null;
        form.reset();
    };

    if (editingId.value !== null) {
        form.put(updateStock.url(editingId.value), {
            preserveScroll: true,
            onSuccess,
        });
    } else {
        form.post(storeStock.url(), { preserveScroll: true, onSuccess });
    }
}

function destroy(id: number) {
    router.delete(destroyStock.url(id), { preserveScroll: true });
}

function resetHistoricalUpload() {
    historicalForm.clearErrors();
    historicalForm.file = null;
    manualFileName.value = '';
    manualDropActive.value = false;

    if (manualFileInput.value) {
        manualFileInput.value.value = '';
    }
}

function resetStooqUpload() {
    stooqForm.clearErrors();
    stooqForm.files = [];
    importQueue.value = [];
    stooqDropActive.value = false;

    if (stooqFileInput.value) {
        stooqFileInput.value.value = '';
    }
}

function selectManualFile(event: Event) {
    const input = event.target as HTMLInputElement;
    setManualFile(input.files?.[0] ?? null);
}

function selectStooqFile(event: Event) {
    const input = event.target as HTMLInputElement;
    setStooqFiles(input.files);
}

function setManualFile(file: File | null) {
    historicalForm.file = file;
    manualFileName.value = file?.name ?? '';
}

function setStooqFiles(files: FileList | File[] | null | undefined) {
    stooqForm.clearErrors();
    stooqForm.files = files ? Array.from(files) : [];
    importQueue.value = stooqForm.files.map((file) => ({
        id: `import-${Date.now()}-${importQueueId++}`,
        file,
        status: 'queued',
        progress: 0,
        result: null,
        error: null,
    }));
}

function dropManualFile(event: DragEvent) {
    manualDropActive.value = false;
    setManualFile(event.dataTransfer?.files?.[0] ?? null);
}

function dropStooqFile(event: DragEvent) {
    stooqDropActive.value = false;
    setStooqFiles(event.dataTransfer?.files);
}

function submitHistoricalImport() {
    if (editingId.value === null) {
        return;
    }

    historicalForm.post(importHistoricalPrices.url(editingId.value), {
        preserveScroll: true,
        onSuccess: resetHistoricalUpload,
    });
}

function submitStooqImport() {
    void runBulkImportQueue();
}

function fetchAllFromStooq() {
    if (stooqFetchingAll.value) {
        return;
    }

    router.post(
        fetchAllStooq.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                stooqFetchingAll.value = true;
            },
            onFinish: () => {
                stooqFetchingAll.value = false;
            },
        },
    );
}

function fetchStockFromStooq() {
    if (editingId.value === null || stooqFetchingStock.value) {
        return;
    }

    router.post(
        fetchStockStooq.url(editingId.value),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                stooqFetchingStock.value = true;
            },
            onFinish: () => {
                stooqFetchingStock.value = false;
            },
        },
    );
}

function openBulkImport() {
    bulkImportOpen.value = true;
}

function clearBulkImportQueue() {
    if (importQueueRunning.value) {
        return;
    }

    resetStooqUpload();
}

async function runBulkImportQueue() {
    if (importQueueRunning.value || importQueue.value.length === 0) {
        return;
    }

    importQueueRunning.value = true;
    stooqForm.clearErrors();

    for (const item of importQueue.value) {
        if (item.status === 'done') {
            continue;
        }

        await importBulkQueueItem(item);
    }

    activeQueueItemId.value = null;
    importQueueRunning.value = false;
}

function importBulkQueueItem(item: ImportQueueItem): Promise<void> {
    item.status = 'running';
    item.progress = 0;
    item.error = null;
    item.result = null;
    activeQueueItemId.value = item.id;

    return new Promise((resolve) => {
        router.post(
            importStooqHistoricalPrices.url(),
            {
                files: [item.file],
                fallback_market: stooqForm.fallback_market,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onProgress: (progress) => {
                    item.progress = Math.max(
                        item.progress,
                        Math.round(progress?.percentage ?? item.progress),
                    );
                },
                onSuccess: () => {
                    item.status = 'done';
                    item.progress = 100;
                },
                onError: (errors) => {
                    item.status = 'failed';
                    item.error = firstError(errors);
                },
                onFinish: () => {
                    if (item.status === 'running') {
                        item.status = 'done';
                        item.progress = 100;
                    }

                    resolve();
                },
            },
        );
    });
}

function firstError(errors: Record<string, string>): string {
    return Object.values(errors)[0] ?? 'Import failed.';
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function importQueueItemDetail(item: ImportQueueItem): string {
    if (item.status === 'failed') {
        return item.error ?? 'Import failed.';
    }

    if (item.result) {
        return `${item.result.imported} candles · ${item.result.created} new · ${item.result.updated} updated · ${item.result.skipped} skipped`;
    }

    if (item.status === 'running') {
        return `${item.progress}%`;
    }

    return formatFileSize(item.file.size);
}
</script>

<template>
    <Head title="Admin · Stocks" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <div
            class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    >
                        <Database class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-semibold text-foreground">
                            Bulk historical import
                        </h1>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Generic CSV or Stooq TXT · thousands of candles ·
                            upsert by stock, timeframe, datetime
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <div
                        v-if="importResult"
                        class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-xs dark:border-sidebar-border"
                    >
                        <span class="font-medium text-foreground">{{
                            latestImportTitle
                        }}</span>
                        <span class="ml-2 text-muted-foreground">
                            {{ importResult.imported }} imported ·
                            {{ importResult.skipped }} skipped
                        </span>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="stooqFetchingAll"
                        @click="fetchAllFromStooq"
                    >
                        <Loader2
                            v-if="stooqFetchingAll"
                            class="size-4 animate-spin"
                        />
                        <DownloadCloud v-else class="size-4" />
                        Update from Stooq (NASDAQ)
                    </Button>
                    <Button type="button" @click="openBulkImport">
                        <UploadCloud class="size-4" />
                        Import historical data
                    </Button>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <Input
                v-model="search"
                type="search"
                placeholder="Search stocks…"
                class="max-w-xs"
            />
            <Button size="sm" @click="openCreate">
                <Plus class="size-4" />
                New stock
            </Button>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
        >
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-sidebar-border/70 text-left text-xs tracking-wide text-muted-foreground uppercase dark:border-sidebar-border"
                        >
                            <th class="px-4 py-2 font-medium">Symbol</th>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Market</th>
                            <th class="px-4 py-2 font-medium">Sector</th>
                            <th class="px-4 py-2 font-medium">Active</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                    >
                        <tr
                            v-for="stock in stocks.data"
                            :key="stock.id"
                            class="hover:bg-accent"
                        >
                            <td class="px-4 py-2 font-medium text-foreground">
                                {{ stock.symbol }}
                            </td>
                            <td class="px-4 py-2 text-foreground">
                                {{ stock.name }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ stock.market }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ stock.sector ?? '—' }}
                            </td>
                            <td class="px-4 py-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        stock.is_active
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ stock.is_active ? 'Yes' : 'No' }}
                                </span>
                            </td>
                            <td class="px-4 py-2">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="openEdit(stock)"
                                    >
                                        <Pencil class="size-4" />
                                        Edit
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        @click="destroy(stock.id)"
                                    >
                                        <Trash2 class="size-4" />
                                        Delete
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="stocks.data.length === 0">
                            <td
                                colspan="6"
                                class="px-4 py-8 text-center text-sm text-muted-foreground"
                            >
                                No stocks found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            class="flex items-center justify-between text-sm text-muted-foreground"
        >
            <span
                >Page {{ stocks.current_page }} of {{ stocks.last_page }}</span
            >
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

        <Dialog v-model:open="bulkImportOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Historical import queue</DialogTitle>
                </DialogHeader>

                <div class="grid gap-4 lg:grid-cols-[12rem_minmax(0,1fr)]">
                    <div class="space-y-1.5">
                        <Label for="stooq-fallback-market"
                            >Fallback market</Label
                        >
                        <select
                            id="stooq-fallback-market"
                            v-model="stooqForm.fallback_market"
                            :disabled="importQueueRunning"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <option value="ALL">All markets</option>
                            <option
                                v-for="m in options.markets"
                                :key="m.value"
                                :value="m.value"
                            >
                                {{ m.value }}
                            </option>
                        </select>
                        <p
                            v-if="stooqForm.errors.fallback_market"
                            class="text-xs text-destructive"
                        >
                            {{ stooqForm.errors.fallback_market }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="stooq-file">Bulk files</Label>
                        <label
                            for="stooq-file"
                            class="flex min-h-36 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed bg-background px-4 py-4 text-center text-sm transition-colors hover:bg-accent"
                            :class="[
                                stooqDropActive
                                    ? 'border-primary ring-2 ring-primary/20'
                                    : 'border-input',
                                importQueueRunning
                                    ? 'pointer-events-none opacity-60'
                                    : '',
                            ]"
                            @dragenter.prevent="stooqDropActive = true"
                            @dragover.prevent="stooqDropActive = true"
                            @dragleave.prevent="stooqDropActive = false"
                            @drop.prevent="dropStooqFile"
                        >
                            <Upload
                                class="size-6 shrink-0 text-muted-foreground"
                            />
                            <span
                                class="max-w-full truncate font-medium text-foreground"
                                >{{ stooqFileLabel }}</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >CSV, TXT · multiple files supported</span
                            >
                            <span
                                v-if="stooqFilePreview"
                                class="max-w-full truncate text-xs text-muted-foreground"
                                >{{ stooqFilePreview }}</span
                            >
                        </label>
                        <input
                            id="stooq-file"
                            ref="stooqFileInput"
                            type="file"
                            accept=".txt,.csv,text/plain,text/csv"
                            multiple
                            class="hidden"
                            :disabled="importQueueRunning"
                            @change="selectStooqFile"
                        />
                        <p
                            v-if="stooqFileError"
                            class="text-xs text-destructive"
                        >
                            {{ stooqFileError }}
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            CSV: symbol, market, timeframe, datetime, open,
                            high, low, close, volume, name
                        </p>
                    </div>
                </div>

                <div
                    class="rounded-lg border border-sidebar-border/70 bg-background dark:border-sidebar-border"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                    >
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-foreground">
                                Queue
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ importQueueCompletedCount }} /
                                {{ importQueue.length }} processed
                                <span v-if="importQueue.length">
                                    · {{ importQueueSuccessCount }} done ·
                                    {{ importQueueFailedCount }} failed</span
                                >
                            </div>
                        </div>
                        <div
                            class="h-1.5 w-full overflow-hidden rounded-full bg-muted sm:w-48"
                        >
                            <div
                                class="h-full rounded-full bg-primary transition-all"
                                :style="{ width: `${importQueueProgress}%` }"
                            />
                        </div>
                    </div>

                    <div
                        v-if="importQueue.length"
                        class="max-h-80 overflow-y-auto"
                    >
                        <div
                            v-for="item in importQueue"
                            :key="item.id"
                            class="flex items-center gap-3 border-b border-sidebar-border/70 px-4 py-3 last:border-b-0 dark:border-sidebar-border"
                        >
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted"
                            >
                                <CheckCircle2
                                    v-if="item.status === 'done'"
                                    class="size-4 text-emerald-600 dark:text-emerald-400"
                                />
                                <Loader2
                                    v-else-if="item.status === 'running'"
                                    class="size-4 animate-spin text-primary"
                                />
                                <XCircle
                                    v-else-if="item.status === 'failed'"
                                    class="size-4 text-destructive"
                                />
                                <Clock3
                                    v-else
                                    class="size-4 text-muted-foreground"
                                />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div
                                    class="truncate text-sm font-medium text-foreground"
                                >
                                    {{ item.file.name }}
                                </div>
                                <div
                                    class="truncate text-xs"
                                    :class="
                                        item.status === 'failed'
                                            ? 'text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ importQueueItemDetail(item) }}
                                </div>
                                <div
                                    v-if="item.status === 'running'"
                                    class="mt-2 h-1 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full bg-primary transition-all"
                                        :style="{ width: `${item.progress}%` }"
                                    />
                                </div>
                            </div>

                            <div
                                class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="{
                                    'bg-muted text-muted-foreground':
                                        item.status === 'queued',
                                    'bg-primary/10 text-primary':
                                        item.status === 'running',
                                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300':
                                        item.status === 'done',
                                    'bg-destructive/10 text-destructive':
                                        item.status === 'failed',
                                }"
                            >
                                {{ item.status }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="px-4 py-8 text-center text-sm text-muted-foreground"
                    >
                        No files selected.
                    </div>
                </div>

                <div
                    v-if="importResult?.errors.length"
                    class="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-xs text-destructive"
                >
                    <ul class="space-y-1">
                        <li v-for="error in importResult.errors" :key="error">
                            {{ error }}
                        </li>
                    </ul>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="
                            importQueueRunning || importQueue.length === 0
                        "
                        @click="clearBulkImportQueue"
                    >
                        Clear
                    </Button>
                    <Button
                        type="button"
                        :disabled="
                            importQueueRunning || importQueue.length === 0
                        "
                        @click="submitStooqImport"
                    >
                        <Loader2
                            v-if="importQueueRunning"
                            class="size-4 animate-spin"
                        />
                        <Upload v-else class="size-4" />
                        {{ importQueueRunning ? 'Importing' : 'Start queue' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{{
                        editingId !== null ? 'Edit stock' : 'New stock'
                    }}</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <Label for="symbol">Symbol</Label>
                            <Input id="symbol" v-model="form.symbol" />
                            <p
                                v-if="form.errors.symbol"
                                class="text-xs text-destructive"
                            >
                                {{ form.errors.symbol }}
                            </p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="market">Market</Label>
                            <select
                                id="market"
                                v-model="form.market"
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option
                                    v-for="m in options.markets"
                                    :key="m.value"
                                    :value="m.value"
                                >
                                    {{ m.label }}
                                </option>
                            </select>
                            <p
                                v-if="form.errors.market"
                                class="text-xs text-destructive"
                            >
                                {{ form.errors.market }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" />
                        <p
                            v-if="form.errors.name"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <Label for="exchange">Exchange</Label>
                            <Input id="exchange" v-model="form.exchange" />
                            <p
                                v-if="form.errors.exchange"
                                class="text-xs text-destructive"
                            >
                                {{ form.errors.exchange }}
                            </p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="currency">Currency</Label>
                            <Input id="currency" v-model="form.currency" />
                            <p
                                v-if="form.errors.currency"
                                class="text-xs text-destructive"
                            >
                                {{ form.errors.currency }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="sector">Sector</Label>
                        <Input id="sector" v-model="form.sector" />
                        <p
                            v-if="form.errors.sector"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.sector }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="aliases"
                            >Aliases
                            <span class="text-muted-foreground"
                                >(comma-separated)</span
                            ></Label
                        >
                        <Input id="aliases" v-model="form.aliases" />
                        <p
                            v-if="form.errors.aliases"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.aliases }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="keywords"
                            >Keywords
                            <span class="text-muted-foreground"
                                >(comma-separated)</span
                            ></Label
                        >
                        <Input id="keywords" v-model="form.keywords" />
                        <p
                            v-if="form.errors.keywords"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.keywords }}
                        </p>
                    </div>

                    <label
                        class="flex items-center gap-2 text-sm text-foreground"
                    >
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 rounded border-input"
                        />
                        Active
                    </label>

                    <div
                        v-if="editingId !== null"
                        class="rounded-lg border border-sidebar-border/70 bg-background p-4 dark:border-sidebar-border"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                                >
                                    <FileText class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <h2
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        Historical CSV
                                    </h2>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ editingStock?.symbol }} · datetime,
                                        open, high, low, close, volume
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-4 grid gap-4 lg:grid-cols-[11rem_minmax(0,1fr)]"
                        >
                            <div class="space-y-1.5">
                                <Label for="historical-timeframe"
                                    >Timeframe</Label
                                >
                                <select
                                    id="historical-timeframe"
                                    v-model="historicalForm.timeframe"
                                    class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option
                                        v-for="timeframe in options.timeframes"
                                        :key="timeframe.value"
                                        :value="timeframe.value"
                                    >
                                        {{ timeframe.value }}
                                    </option>
                                </select>
                                <p
                                    v-if="historicalForm.errors.timeframe"
                                    class="text-xs text-destructive"
                                >
                                    {{ historicalForm.errors.timeframe }}
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="historical-file">CSV file</Label>
                                <label
                                    for="historical-file"
                                    class="flex min-h-28 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed bg-background px-4 py-4 text-center text-sm transition-colors hover:bg-accent"
                                    :class="
                                        manualDropActive
                                            ? 'border-primary ring-2 ring-primary/20'
                                            : 'border-input'
                                    "
                                    @dragenter.prevent="manualDropActive = true"
                                    @dragover.prevent="manualDropActive = true"
                                    @dragleave.prevent="
                                        manualDropActive = false
                                    "
                                    @drop.prevent="dropManualFile"
                                >
                                    <Upload
                                        class="size-5 shrink-0 text-muted-foreground"
                                    />
                                    <span
                                        class="max-w-full truncate font-medium text-foreground"
                                        >{{
                                            manualFileName ||
                                            'Drop CSV here or click to browse'
                                        }}</span
                                    >
                                </label>
                                <input
                                    id="historical-file"
                                    ref="manualFileInput"
                                    type="file"
                                    accept=".csv,.txt,text/csv,text/plain"
                                    class="hidden"
                                    @change="selectManualFile"
                                />
                                <p
                                    v-if="historicalForm.errors.file"
                                    class="text-xs text-destructive"
                                >
                                    {{ historicalForm.errors.file }}
                                </p>
                            </div>
                        </div>

                        <div
                            class="mt-4 flex flex-wrap items-center justify-between gap-3"
                        >
                            <div class="min-w-0 flex-1">
                                <div
                                    v-if="historicalForm.progress"
                                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full bg-primary transition-all"
                                        :style="{
                                            width: `${historicalForm.progress.percentage}%`,
                                        }"
                                    />
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    v-if="editingStock?.market === 'NASDAQ'"
                                    type="button"
                                    variant="ghost"
                                    class="w-full sm:w-auto"
                                    :disabled="stooqFetchingStock"
                                    @click="fetchStockFromStooq"
                                >
                                    <Loader2
                                        v-if="stooqFetchingStock"
                                        class="size-4 animate-spin"
                                    />
                                    <DownloadCloud v-else class="size-4" />
                                    Fetch from Stooq (daily)
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="w-full sm:w-auto"
                                    :disabled="
                                        historicalForm.processing ||
                                        !historicalForm.file
                                    "
                                    @click="submitHistoricalImport"
                                >
                                    <Loader2
                                        v-if="historicalForm.processing"
                                        class="size-4 animate-spin"
                                    />
                                    <Upload v-else class="size-4" />
                                    Upload CSV
                                </Button>
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="form.processing">
                            {{
                                editingId !== null
                                    ? 'Save changes'
                                    : 'Create stock'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
