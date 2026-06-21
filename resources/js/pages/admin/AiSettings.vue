<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Pencil, Play, Plus, Save, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    destroyModel,
    destroyProvider,
    enableProviderModels,
    storeModel,
    storeProvider,
    testModel,
    testTask,
    toggleModel,
    updateModel,
    updateProvider,
    updateSettings,
    updateTask,
} from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
import AdminNav from '@/components/tradenews/AdminNav.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AiModel = {
    id: number;
    api_provider_id: number;
    name: string;
    model: string;
    task: string | null;
    runtime: string | null;
    endpoint_url: string | null;
    is_active: boolean;
    status: string;
    status_label: string;
    status_color: string;
    last_error: string | null;
    last_latency_ms: number | null;
    last_checked_at: string | null;
    max_output_tokens: number;
    temperature: number | null;
    is_selected: boolean;
};

type EnumOption = { value: string; label: string };

type AiTaskRow = {
    task: string;
    label: string;
    default_runtime: string;
    enabled: boolean;
    active_ai_model_id: number | null;
    fallback_behavior: string | null;
    status: string | null;
    status_label: string | null;
    status_color: string | null;
    last_error: string | null;
    last_latency_ms: number | null;
    last_checked_at: string | null;
    models: SelectOption[];
};

type AiProvider = {
    id: number;
    key: string;
    name: string;
    base_url: string | null;
    status: string;
    status_label: string;
    status_color: string;
    is_active: boolean;
    auto_recovery: boolean;
    api_key_configured: boolean;
    priority: number;
    refresh_interval_minutes: number;
    last_latency_ms: number | null;
    last_error: string | null;
    last_checked_at: string | null;
    models: AiModel[];
};

type StatusPayload = {
    state: string;
    label: string;
    message: string;
    color: string;
    last_error: string | null;
    last_latency_ms: number | null;
    last_checked_at: string | null;
};

type ProviderOption = {
    key: string;
    name: string;
    base_url: string;
};

type SelectOption = {
    value: number;
    label: string;
};

type SettingsForm = {
    enabled: boolean;
    active_ai_model_id: number | null;
};

type ProviderForm = {
    key: string;
    name: string;
    base_url: string;
    api_key: string;
    clear_api_key: boolean;
    is_active: boolean;
    auto_recovery: boolean;
    priority: number;
    refresh_interval_minutes: number;
};

type ModelForm = {
    api_provider_id: number | null;
    name: string;
    model: string;
    task: string | null;
    runtime: string | null;
    endpoint_url: string;
    is_active: boolean;
    max_output_tokens: number;
    temperature: number | null;
};

const props = defineProps<{
    settings: SettingsForm;
    status: StatusPayload;
    providers: AiProvider[];
    providerOptions: ProviderOption[];
    tasks: AiTaskRow[];
    taskOptions: EnumOption[];
    runtimeOptions: EnumOption[];
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

const bannerClasses: Record<string, string> = {
    emerald: 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
    amber: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
    rose: 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200',
    slate: 'border-sidebar-border/70 bg-card text-foreground dark:border-sidebar-border',
};

const outputTokenDefaults: Record<string, number> = {
    summary: 300,
    stock_analysis: 700,
    translation: 900,
    sentiment_tr: 160,
    sentiment_en: 160,
    entity_tr: 160,
    entity_en: 160,
    embedding: 160,
    reranker: 160,
};

const providerDialogOpen = ref(false);
const modelDialogOpen = ref(false);
const editingProviderId = ref<number | null>(null);
const editingProviderKeyConfigured = ref(false);
const editingModelId = ref<number | null>(null);

const activeModelOptions = computed<SelectOption[]>(() =>
    props.providers.flatMap((provider) =>
        provider.models.map((model) => ({
            value: model.id,
            label: `${provider.name} / ${model.name} (${model.model})`,
        })),
    ),
);

const settingsForm = useForm<SettingsForm>({
    enabled: props.settings.enabled,
    active_ai_model_id: props.settings.active_ai_model_id,
});

const providerForm = useForm<ProviderForm>({
    key: props.providerOptions[0]?.key ?? 'openai',
    name: props.providerOptions[0]?.name ?? 'OpenAI',
    base_url: props.providerOptions[0]?.base_url ?? 'https://api.openai.com/v1',
    api_key: '',
    clear_api_key: false,
    is_active: true,
    auto_recovery: true,
    priority: 100,
    refresh_interval_minutes: 30,
});

const modelForm = useForm<ModelForm>({
    api_provider_id: props.providers[0]?.id ?? null,
    name: '',
    model: '',
    task: null,
    runtime: null,
    endpoint_url: '',
    is_active: true,
    max_output_tokens: 160,
    temperature: null,
});

// Editable local copies of each task row (committed via saveTask).
const taskRows = ref<AiTaskRow[]>(props.tasks.map((row) => ({ ...row })));
const translationTask = computed<AiTaskRow | null>(() => taskRows.value.find((row) => row.task === 'translation') ?? null);
const taskRowsWithoutTranslation = computed<AiTaskRow[]>(() => taskRows.value.filter((row) => row.task !== 'translation'));

watch(
    () => props.tasks,
    (rows) => {
        taskRows.value = rows.map((row) => ({ ...row }));
    },
);

function saveTask(row: AiTaskRow) {
    router.patch(
        updateTask.url(row.task),
        {
            enabled: row.enabled,
            active_ai_model_id: row.active_ai_model_id,
            fallback_behavior: row.fallback_behavior,
        },
        { preserveScroll: true },
    );
}

function testTaskConnection(row: AiTaskRow) {
    router.post(testTask.url(row.task), {}, { preserveScroll: true });
}

function setFallback(row: AiTaskRow, value: string | number) {
    row.fallback_behavior = String(value) || null;
}

function saveTranslationTask() {
    if (translationTask.value) {
        saveTask(translationTask.value);
    }
}

function testTranslationTaskConnection() {
    if (translationTask.value) {
        testTaskConnection(translationTask.value);
    }
}

function setTranslationFallback(value: string | number) {
    if (translationTask.value) {
        setFallback(translationTask.value, value);
    }
}

const modelTemperature = computed<string | number>({
    get: () => modelForm.temperature ?? '',
    set: (value) => {
        modelForm.temperature = value === '' ? null : Number(value);
    },
});

watch(
    () => modelForm.task,
    (task) => {
        if (editingModelId.value === null) {
            modelForm.max_output_tokens = task !== null ? (outputTokenDefaults[task] ?? 160) : 160;
        }
    },
);

const dotClass = (color: string): string => dotColors[color] ?? dotColors.slate;
const bannerClass = (color: string): string => bannerClasses[color] ?? bannerClasses.slate;

function saveSettings() {
    settingsForm.patch(updateSettings.url(), { preserveScroll: true });
}

function resetProviderForm() {
    editingProviderId.value = null;
    editingProviderKeyConfigured.value = false;
    providerForm.clearErrors();
    providerForm.key = props.providerOptions[0]?.key ?? 'openai';
    providerForm.name = props.providerOptions[0]?.name ?? 'OpenAI';
    providerForm.base_url = props.providerOptions[0]?.base_url ?? 'https://api.openai.com/v1';
    providerForm.api_key = '';
    providerForm.clear_api_key = false;
    providerForm.is_active = true;
    providerForm.auto_recovery = true;
    providerForm.priority = 100;
    providerForm.refresh_interval_minutes = 30;
}

function applyProviderPreset() {
    if (editingProviderId.value !== null) {
        return;
    }

    const option = props.providerOptions.find((candidate) => candidate.key === providerForm.key);

    if (option) {
        providerForm.name = option.name;
        providerForm.base_url = option.base_url;
    }
}

function openProviderCreate() {
    resetProviderForm();
    providerDialogOpen.value = true;
}

function openProviderEdit(provider: AiProvider) {
    editingProviderId.value = provider.id;
    editingProviderKeyConfigured.value = provider.api_key_configured;
    providerForm.clearErrors();
    providerForm.key = provider.key;
    providerForm.name = provider.name;
    providerForm.base_url = provider.base_url ?? '';
    providerForm.api_key = '';
    providerForm.clear_api_key = false;
    providerForm.is_active = provider.is_active;
    providerForm.auto_recovery = provider.auto_recovery;
    providerForm.priority = provider.priority;
    providerForm.refresh_interval_minutes = provider.refresh_interval_minutes;
    providerDialogOpen.value = true;
}

function submitProvider() {
    const options = { preserveScroll: true, onSuccess: () => (providerDialogOpen.value = false) };

    if (editingProviderId.value !== null) {
        providerForm.put(updateProvider.url(editingProviderId.value), options);

        return;
    }

    providerForm.post(storeProvider.url(), options);
}

function removeProvider(provider: AiProvider) {
    if (confirm(`Delete AI provider "${provider.name}"? Models under it will also be deleted.`)) {
        router.delete(destroyProvider.url(provider.id), { preserveScroll: true });
    }
}

function resetModelForm(providerId: number | null = props.providers[0]?.id ?? null) {
    editingModelId.value = null;
    modelForm.clearErrors();
    modelForm.api_provider_id = providerId;
    modelForm.name = '';
    modelForm.model = '';
    modelForm.task = null;
    modelForm.runtime = null;
    modelForm.endpoint_url = '';
    modelForm.is_active = true;
    modelForm.max_output_tokens = 160;
    modelForm.temperature = null;
}

function openModelCreate(provider?: AiProvider) {
    resetModelForm(provider?.id ?? props.providers[0]?.id ?? null);
    modelDialogOpen.value = true;
}

function openModelEdit(model: AiModel) {
    editingModelId.value = model.id;
    modelForm.clearErrors();
    modelForm.api_provider_id = model.api_provider_id;
    modelForm.name = model.name;
    modelForm.model = model.model;
    modelForm.task = model.task;
    modelForm.runtime = model.runtime;
    modelForm.endpoint_url = model.endpoint_url ?? '';
    modelForm.is_active = model.is_active;
    modelForm.max_output_tokens = model.max_output_tokens;
    modelForm.temperature = model.temperature;
    modelDialogOpen.value = true;
}

function submitModel() {
    const options = { preserveScroll: true, onSuccess: () => (modelDialogOpen.value = false) };

    if (editingModelId.value !== null) {
        modelForm.put(updateModel.url(editingModelId.value), options);

        return;
    }

    modelForm.post(storeModel.url(), options);
}

function removeModel(model: AiModel) {
    if (confirm(`Delete AI model "${model.name}"?`)) {
        router.delete(destroyModel.url(model.id), { preserveScroll: true });
    }
}

function hasDisabledModels(provider: AiProvider): boolean {
    return provider.models.some((model) => !model.is_active);
}

function enableAllProviderModels(provider: AiProvider) {
    router.patch(enableProviderModels.url(provider.id), {}, { preserveScroll: true });
}

function toggleModelEnabled(model: AiModel) {
    router.patch(toggleModel.url(model.id), {}, { preserveScroll: true });
}

function testConnection(model: AiModel) {
    router.post(testModel.url(model.id), {}, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <Head title="Admin · AI Settings" />

        <AdminNav />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-foreground">AI Settings</h1>
                <p class="mt-1 text-sm text-muted-foreground">Manage AI providers, per-task models, translation, and provider endpoints.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" @click="openModelCreate()">
                    <Plus class="size-4" />
                    New model
                </Button>
                <Button size="sm" @click="openProviderCreate">
                    <Plus class="size-4" />
                    New provider
                </Button>
            </div>
        </div>

        <section class="rounded-lg border p-4" :class="bannerClass(status.color)">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full" :class="dotClass(status.color)" />
                        <h2 class="text-sm font-semibold">{{ status.label }}</h2>
                    </div>
                    <p class="mt-1 text-sm opacity-90">{{ status.message }}</p>
                    <div class="mt-2 flex flex-wrap gap-3 text-xs opacity-80">
                        <span>{{ status.last_latency_ms !== null ? status.last_latency_ms + ' ms' : 'latency not recorded' }}</span>
                        <span>{{ status.last_checked_at ? 'checked ' + status.last_checked_at : 'not checked yet' }}</span>
                    </div>
                </div>
                <p v-if="status.last_error" class="max-w-xl break-all text-xs text-rose-700 dark:text-rose-300">{{ status.last_error }}</p>
            </div>
        </section>

        <form class="rounded-lg border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border" @submit.prevent="saveSettings">
            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                <div class="grid gap-3 md:grid-cols-[auto_minmax(0,1fr)] md:items-end">
                    <label class="inline-flex h-9 items-center gap-2 text-sm font-medium text-foreground">
                        <input v-model="settingsForm.enabled" type="checkbox" class="size-4 rounded border-input accent-primary" />
                        AI enabled
                    </label>

                    <div class="space-y-1.5">
                        <Label for="active-ai-model">Global fallback model</Label>
                        <select
                            id="active-ai-model"
                            v-model="settingsForm.active_ai_model_id"
                            class="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px] dark:bg-input/30"
                            :aria-invalid="Boolean(settingsForm.errors.active_ai_model_id)"
                        >
                            <option :value="null">No fallback model</option>
                            <option v-for="option in activeModelOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <p v-if="settingsForm.errors.active_ai_model_id" class="text-xs text-destructive">{{ settingsForm.errors.active_ai_model_id }}</p>
                        <p class="text-xs text-muted-foreground">Per-task models below can all stay enabled; this is only the legacy fallback selector.</p>
                    </div>
                </div>

                <Button type="submit" size="sm" :disabled="settingsForm.processing">
                    <Save class="size-4" />
                    Save settings
                </Button>
            </div>
        </form>

        <section v-if="translationTask" class="rounded-lg border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-sm font-semibold text-foreground">Translation settings</h2>
                        <span v-if="translationTask.status" class="inline-flex items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                            <span class="size-2 rounded-full" :class="dotClass(translationTask.status_color ?? 'slate')" />
                            {{ translationTask.status_label }}
                            <span v-if="translationTask.last_latency_ms !== null">· {{ translationTask.last_latency_ms }} ms</span>
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Use the Hugging Face multilingual/chat model for cached UI translations. DeepL stays available here as a secondary provider option.
                    </p>
                    <p v-if="translationTask.last_error" class="mt-2 max-w-3xl break-all text-xs text-rose-600 dark:text-rose-400">{{ translationTask.last_error }}</p>
                </div>

                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <Button type="button" size="sm" variant="outline" :disabled="translationTask.active_ai_model_id === null" @click="testTranslationTaskConnection">
                        <Play class="size-4" />
                        Test
                    </Button>
                    <Button type="button" size="sm" @click="saveTranslationTask">
                        <Save class="size-4" />
                        Save
                    </Button>
                </div>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-[auto_minmax(0,1fr)_minmax(10rem,14rem)] lg:items-end">
                <label class="inline-flex h-9 items-center gap-2 text-sm font-medium text-foreground">
                    <input v-model="translationTask.enabled" type="checkbox" class="size-4 rounded border-input accent-primary" @change="saveTranslationTask" />
                    Translation enabled
                </label>

                <div class="space-y-1.5">
                    <Label for="translation-model">Translation model</Label>
                    <select
                        id="translation-model"
                        v-model="translationTask.active_ai_model_id"
                        class="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px] dark:bg-input/30"
                        @change="saveTranslationTask"
                    >
                        <option :value="null">No translation model</option>
                        <option v-for="option in translationTask.models" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <Label for="translation-fallback">Fallback</Label>
                    <Input
                        id="translation-fallback"
                        :model-value="translationTask.fallback_behavior ?? ''"
                        class="h-9"
                        placeholder="original"
                        @update:model-value="setTranslationFallback"
                        @blur="saveTranslationTask"
                    />
                </div>
            </div>
        </section>

        <!-- Task matrix -->
        <section class="rounded-lg border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <h2 class="text-sm font-semibold text-foreground">AI tasks</h2>
            <p class="mt-1 text-sm text-muted-foreground">Enable AI per task and pick the model it uses. Disabled tasks fall back to the deterministic pipeline.</p>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="row in taskRowsWithoutTranslation"
                    :key="row.task"
                    class="flex flex-col gap-2.5 rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                >
                    <div class="flex items-start justify-between gap-2">
                        <span class="min-w-0 truncate text-sm font-medium text-foreground" :title="row.label">{{ row.label }}</span>
                        <label class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 text-[11px] text-muted-foreground">
                            <input type="checkbox" class="size-4 rounded border-input accent-primary" v-model="row.enabled" @change="saveTask(row)" />
                            Enabled
                        </label>
                    </div>

                    <select
                        v-model="row.active_ai_model_id"
                        class="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-2 text-sm shadow-xs outline-none dark:bg-input/30"
                        @change="saveTask(row)"
                    >
                        <option :value="null">No model</option>
                        <option v-for="option in row.models" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>

                    <div class="flex items-center justify-between gap-2">
                        <span v-if="row.status" class="inline-flex min-w-0 items-center gap-1.5 truncate text-xs text-muted-foreground">
                            <span class="size-2 shrink-0 rounded-full" :class="dotClass(row.status_color ?? 'slate')" />
                            {{ row.status_label }}
                            <span v-if="row.last_latency_ms !== null">· {{ row.last_latency_ms }} ms</span>
                        </span>
                        <span v-else class="text-xs text-muted-foreground">—</span>

                        <Button type="button" size="sm" variant="outline" class="shrink-0" :disabled="row.active_ai_model_id === null" @click="testTaskConnection(row)">
                            <Play class="size-4" />
                            Test
                        </Button>
                    </div>

                    <p v-if="row.last_error" class="truncate text-[11px] text-rose-600 dark:text-rose-400" :title="row.last_error">{{ row.last_error }}</p>
                </div>
            </div>
        </section>

        <div class="grid gap-4 grid-cols-[repeat(auto-fit,minmax(min(100%,28rem),1fr))]">
            <section
                v-for="provider in providers"
                :key="provider.id"
                class="flex min-w-0 flex-col rounded-lg border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="size-2 rounded-full" :class="dotClass(provider.status_color)" />
                            <h2 class="truncate text-sm font-semibold text-foreground">{{ provider.name }}</h2>
                            <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">{{ provider.key }}</span>
                            <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">{{ provider.status_label }}</span>
                            <span v-if="provider.api_key_configured" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">key set</span>
                            <span v-if="!provider.is_active" class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">disabled</span>
                        </div>
                        <p v-if="provider.base_url" class="mt-1 truncate text-xs text-muted-foreground">{{ provider.base_url }}</p>
                        <div class="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                            <span>priority {{ provider.priority }}</span>
                            <span>{{ provider.refresh_interval_minutes }}m health check</span>
                            <span>{{ provider.last_latency_ms !== null ? provider.last_latency_ms + ' ms' : 'no latency' }}</span>
                            <span v-if="provider.last_checked_at">checked {{ provider.last_checked_at }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-1">
                        <Button type="button" size="sm" variant="outline" :disabled="provider.models.length === 0 || !hasDisabledModels(provider)" @click="enableAllProviderModels(provider)">
                            <CheckCircle2 class="size-4" />
                            Enable all
                        </Button>
                        <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground" title="Add model" @click="openModelCreate(provider)">
                            <Plus class="size-4" />
                        </button>
                        <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground" title="Edit provider" @click="openProviderEdit(provider)">
                            <Pencil class="size-4" />
                        </button>
                        <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-destructive/10 hover:text-destructive" title="Delete provider" @click="removeProvider(provider)">
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>

                <p v-if="provider.last_error" class="mt-3 break-all text-xs text-rose-600 dark:text-rose-400">{{ provider.last_error }}</p>

                <div class="mt-4 divide-y divide-sidebar-border/70 overflow-hidden rounded-md border border-sidebar-border/70 dark:divide-sidebar-border dark:border-sidebar-border">
                    <div v-if="provider.models.length === 0" class="px-3 py-5 text-sm text-muted-foreground">No models configured for this provider.</div>

                    <div v-for="model in provider.models" :key="model.id" class="flex flex-wrap items-center justify-between gap-3 px-3 py-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="truncate text-sm font-medium text-foreground">{{ model.name }}</h3>
                                <span class="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">{{ model.model }}</span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px]"
                                    :class="
                                        model.is_active
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                                            : 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
                                    "
                                >
                                    {{ model.is_active ? 'enabled' : 'disabled' }}
                                </span>
                                <span v-if="model.is_selected" class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">global fallback</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ model.max_output_tokens }} max tokens · temperature {{ model.temperature ?? 'default' }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-1">
                            <Button type="button" size="sm" variant="outline" @click="toggleModelEnabled(model)">
                                <CheckCircle2 v-if="!model.is_active" class="size-4" />
                                <X v-else class="size-4" />
                                {{ model.is_active ? 'Disable' : 'Enable' }}
                            </Button>
                            <Button type="button" size="sm" variant="outline" @click="testConnection(model)">
                                <Play class="size-4" />
                                Test
                            </Button>
                            <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground" title="Edit model" @click="openModelEdit(model)">
                                <Pencil class="size-4" />
                            </button>
                            <button type="button" class="rounded-md p-2 text-muted-foreground hover:bg-destructive/10 hover:text-destructive" title="Delete model" @click="removeModel(model)">
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div v-if="providers.length === 0" class="rounded-lg border border-sidebar-border/70 bg-card px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border">
            No AI providers configured.
        </div>

        <Dialog v-model:open="providerDialogOpen">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ editingProviderId ? 'Edit AI provider' : 'New AI provider' }}</DialogTitle>
                </DialogHeader>

                <form class="flex flex-col gap-3" @submit.prevent="submitProvider">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="ai-provider-key">Provider</Label>
                            <select
                                id="ai-provider-key"
                                v-model="providerForm.key"
                                class="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px] dark:bg-input/30"
                                :disabled="editingProviderId !== null"
                                :aria-invalid="Boolean(providerForm.errors.key)"
                                @change="applyProviderPreset"
                            >
                                <option v-for="option in providerOptions" :key="option.key" :value="option.key">{{ option.name }}</option>
                            </select>
                            <p v-if="providerForm.errors.key" class="text-xs text-destructive">{{ providerForm.errors.key }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="ai-provider-name">Name</Label>
                            <Input id="ai-provider-name" v-model="providerForm.name" autocomplete="off" :aria-invalid="Boolean(providerForm.errors.name)" />
                            <p v-if="providerForm.errors.name" class="text-xs text-destructive">{{ providerForm.errors.name }}</p>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="ai-provider-base-url">Base URL</Label>
                        <Input id="ai-provider-base-url" v-model="providerForm.base_url" type="url" autocomplete="off" :aria-invalid="Boolean(providerForm.errors.base_url)" />
                        <p v-if="providerForm.errors.base_url" class="text-xs text-destructive">{{ providerForm.errors.base_url }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="ai-provider-api-key">API key</Label>
                        <Input
                            id="ai-provider-api-key"
                            v-model="providerForm.api_key"
                            type="password"
                            autocomplete="new-password"
                            placeholder="Leave empty to keep saved key"
                            :disabled="providerForm.clear_api_key"
                            :aria-invalid="Boolean(providerForm.errors.api_key)"
                        />
                        <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            <span v-if="editingProviderId && editingProviderKeyConfigured">Saved key is configured.</span>
                            <span v-else-if="editingProviderId">No key is saved.</span>
                            <label v-if="editingProviderId && editingProviderKeyConfigured" class="flex items-center gap-1.5 text-rose-600 dark:text-rose-400">
                                <input v-model="providerForm.clear_api_key" type="checkbox" class="size-3.5 accent-destructive" @change="providerForm.api_key = providerForm.clear_api_key ? '' : providerForm.api_key" />
                                Clear saved key
                            </label>
                        </div>
                        <p v-if="providerForm.errors.api_key" class="text-xs text-destructive">{{ providerForm.errors.api_key }}</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="ai-provider-priority">Priority</Label>
                            <Input id="ai-provider-priority" v-model.number="providerForm.priority" type="number" min="1" max="999" />
                            <p v-if="providerForm.errors.priority" class="text-xs text-destructive">{{ providerForm.errors.priority }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="ai-provider-refresh">Health interval minutes</Label>
                            <Input id="ai-provider-refresh" v-model.number="providerForm.refresh_interval_minutes" type="number" min="1" max="1440" />
                            <p v-if="providerForm.errors.refresh_interval_minutes" class="text-xs text-destructive">{{ providerForm.errors.refresh_interval_minutes }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="inline-flex h-9 items-center gap-2 text-sm font-medium text-foreground">
                            <input v-model="providerForm.is_active" type="checkbox" class="size-4 rounded border-input accent-primary" />
                            Active
                        </label>
                        <label class="inline-flex h-9 items-center gap-2 text-sm font-medium text-foreground">
                            <input v-model="providerForm.auto_recovery" type="checkbox" class="size-4 rounded border-input accent-primary" />
                            Auto recovery
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" @click="providerDialogOpen = false">
                            <X class="size-4" />
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="providerForm.processing">
                            <Save class="size-4" />
                            Save provider
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="modelDialogOpen">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ editingModelId ? 'Edit AI model' : 'New AI model' }}</DialogTitle>
                </DialogHeader>

                <form class="flex flex-col gap-3" @submit.prevent="submitModel">
                    <div class="space-y-1.5">
                        <Label for="ai-model-provider">Provider</Label>
                        <select
                            id="ai-model-provider"
                            v-model="modelForm.api_provider_id"
                            class="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px] dark:bg-input/30"
                            :aria-invalid="Boolean(modelForm.errors.api_provider_id)"
                        >
                            <option :value="null" disabled>Select provider</option>
                            <option v-for="provider in providers" :key="provider.id" :value="provider.id">{{ provider.name }}</option>
                        </select>
                        <p v-if="modelForm.errors.api_provider_id" class="text-xs text-destructive">{{ modelForm.errors.api_provider_id }}</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="ai-model-name">Display name</Label>
                            <Input id="ai-model-name" v-model="modelForm.name" autocomplete="off" placeholder="GPT-4.1 mini" :aria-invalid="Boolean(modelForm.errors.name)" />
                            <p v-if="modelForm.errors.name" class="text-xs text-destructive">{{ modelForm.errors.name }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="ai-model-id">Model ID</Label>
                            <Input id="ai-model-id" v-model="modelForm.model" autocomplete="off" placeholder="gpt-4.1-mini" :aria-invalid="Boolean(modelForm.errors.model)" />
                            <p v-if="modelForm.errors.model" class="text-xs text-destructive">{{ modelForm.errors.model }}</p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="ai-model-task">Task</Label>
                            <select
                                id="ai-model-task"
                                v-model="modelForm.task"
                                class="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px] dark:bg-input/30"
                                :aria-invalid="Boolean(modelForm.errors.task)"
                            >
                                <option :value="null">No task (generic)</option>
                                <option v-for="option in taskOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <p v-if="modelForm.errors.task" class="text-xs text-destructive">{{ modelForm.errors.task }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="ai-model-runtime">Runtime</Label>
                            <select
                                id="ai-model-runtime"
                                v-model="modelForm.runtime"
                                class="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px] dark:bg-input/30"
                                :aria-invalid="Boolean(modelForm.errors.runtime)"
                            >
                                <option :value="null">Default</option>
                                <option v-for="option in runtimeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <p v-if="modelForm.errors.runtime" class="text-xs text-destructive">{{ modelForm.errors.runtime }}</p>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="ai-model-endpoint">Endpoint URL <span class="text-muted-foreground">(optional model override)</span></Label>
                        <Input id="ai-model-endpoint" v-model="modelForm.endpoint_url" type="url" autocomplete="off" placeholder="https://xxxx.endpoints.huggingface.cloud" :aria-invalid="Boolean(modelForm.errors.endpoint_url)" />
                        <p v-if="modelForm.errors.endpoint_url" class="text-xs text-destructive">{{ modelForm.errors.endpoint_url }}</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="ai-model-output">Max output tokens</Label>
                            <Input id="ai-model-output" v-model.number="modelForm.max_output_tokens" type="number" min="1" max="200000" />
                            <p v-if="modelForm.errors.max_output_tokens" class="text-xs text-destructive">{{ modelForm.errors.max_output_tokens }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="ai-model-temp">Temperature</Label>
                            <Input id="ai-model-temp" v-model="modelTemperature" type="number" min="0" max="2" step="0.1" placeholder="Default" />
                            <p v-if="modelForm.errors.temperature" class="text-xs text-destructive">{{ modelForm.errors.temperature }}</p>
                        </div>
                    </div>

                    <label class="inline-flex h-9 items-center gap-2 text-sm font-medium text-foreground">
                        <input v-model="modelForm.is_active" type="checkbox" class="size-4 rounded border-input accent-primary" />
                        Model enabled
                    </label>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" @click="modelDialogOpen = false">
                            <X class="size-4" />
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="modelForm.processing">
                            <Save class="size-4" />
                            Save model
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
