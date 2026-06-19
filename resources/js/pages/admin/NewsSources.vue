<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Power, Save, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    destroyNewsSource,
    storeNewsSource,
    toggleNewsSource,
    updateNewsSource,
} from '@/actions/App/Http/Controllers/Admin/AdminCatalogController';
import AdminNav from '@/components/tradenews/AdminNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Source = {
    id: number;
    key: string;
    name: string;
    provider: string | null;
    market: string | null;
    language: string | null;
    feed_url: string | null;
    homepage_url: string | null;
    is_active: boolean;
    is_rss: boolean;
    news_items_count: number;
};

type MarketOption = {
    value: string | null;
    label: string;
};

type RssSourceForm = {
    key: string;
    name: string;
    feed_url: string;
    homepage_url: string;
    market: string | null;
    language: string | null;
    is_active: boolean;
};

defineProps<{
    sources: Source[];
    marketOptions: MarketOption[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const editingId = ref<number | null>(null);

const form = useForm<RssSourceForm>({
    key: '',
    name: '',
    feed_url: '',
    homepage_url: '',
    market: null,
    language: null,
    is_active: true,
});

const languageOptions = [
    { value: null, label: '—' },
    { value: 'tr', label: 'Turkish (tr)' },
    { value: 'en', label: 'English (en)' },
];

const isEditing = computed(() => editingId.value !== null);

function resetForm() {
    editingId.value = null;
    form.clearErrors();
    form.key = '';
    form.name = '';
    form.feed_url = '';
    form.homepage_url = '';
    form.market = null;
    form.language = null;
    form.is_active = true;
}

function edit(source: Source) {
    if (!source.is_rss) {
        return;
    }

    editingId.value = source.id;
    form.clearErrors();
    form.key = source.key;
    form.name = source.name;
    form.feed_url = source.feed_url ?? '';
    form.homepage_url = source.homepage_url ?? '';
    form.market = source.market;
    form.language = source.language;
    form.is_active = source.is_active;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: resetForm };

    if (editingId.value !== null) {
        form.put(updateNewsSource.url(editingId.value), options);

        return;
    }

    form.post(storeNewsSource.url(), options);
}

function toggle(source: Source) {
    router.patch(toggleNewsSource.url(source.id), {}, { preserveScroll: true });
}

function deactivate(source: Source) {
    if (!source.is_rss || !confirm(`Deactivate RSS source "${source.name}"?`)) {
        return;
    }

    router.delete(destroyNewsSource.url(source.id), { preserveScroll: true });
}

function scopeLabel(market: string | null) {
    return market ?? 'Global';
}
</script>

<template>
    <Head title="Admin · News Sources" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <form class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border" @submit.prevent="submit">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-lg font-semibold text-foreground">{{ isEditing ? 'Edit RSS source' : 'New RSS source' }}</h1>
                <Button v-if="isEditing" type="button" size="sm" variant="outline" @click="resetForm">
                    <X class="size-4" />
                    Cancel
                </Button>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="space-y-1.5">
                    <Label for="news-source-key">Key</Label>
                    <Input id="news-source-key" v-model="form.key" autocomplete="off" :aria-invalid="Boolean(form.errors.key)" />
                    <p v-if="form.errors.key" class="text-xs text-destructive">{{ form.errors.key }}</p>
                </div>

                <div class="space-y-1.5">
                    <Label for="news-source-name">Name</Label>
                    <Input id="news-source-name" v-model="form.name" autocomplete="off" :aria-invalid="Boolean(form.errors.name)" />
                    <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                </div>

                <div class="space-y-1.5 xl:col-span-2">
                    <Label for="news-source-feed-url">Feed URL</Label>
                    <Input id="news-source-feed-url" v-model="form.feed_url" type="url" autocomplete="off" :aria-invalid="Boolean(form.errors.feed_url)" />
                    <p v-if="form.errors.feed_url" class="text-xs text-destructive">{{ form.errors.feed_url }}</p>
                </div>

                <div class="space-y-1.5 md:col-span-2">
                    <Label for="news-source-homepage-url">Homepage</Label>
                    <Input id="news-source-homepage-url" v-model="form.homepage_url" type="url" autocomplete="off" :aria-invalid="Boolean(form.errors.homepage_url)" />
                    <p v-if="form.errors.homepage_url" class="text-xs text-destructive">{{ form.errors.homepage_url }}</p>
                </div>

                <div class="space-y-1.5">
                    <Label for="news-source-market">Scope</Label>
                    <select
                        id="news-source-market"
                        v-model="form.market"
                        class="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px] dark:bg-input/30"
                        :aria-invalid="Boolean(form.errors.market)"
                    >
                        <option v-for="option in marketOptions" :key="option.label" :value="option.value">{{ option.label }}</option>
                    </select>
                    <p v-if="form.errors.market" class="text-xs text-destructive">{{ form.errors.market }}</p>
                </div>

                <div class="space-y-1.5">
                    <Label for="news-source-language">Language</Label>
                    <select
                        id="news-source-language"
                        v-model="form.language"
                        class="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px] dark:bg-input/30"
                        :aria-invalid="Boolean(form.errors.language)"
                    >
                        <option v-for="option in languageOptions" :key="option.label" :value="option.value">{{ option.label }}</option>
                    </select>
                    <p v-if="form.errors.language" class="text-xs text-destructive">{{ form.errors.language }}</p>
                </div>

                <div class="flex items-end justify-between gap-3">
                    <label class="inline-flex h-9 items-center gap-2 text-sm font-medium text-foreground">
                        <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-input accent-primary" />
                        Active
                    </label>
                    <Button type="submit" size="sm" :disabled="form.processing">
                        <Save v-if="isEditing" class="size-4" />
                        <Plus v-else class="size-4" />
                        {{ isEditing ? 'Save' : 'Add RSS' }}
                    </Button>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 text-left text-xs tracking-wide text-muted-foreground uppercase dark:border-sidebar-border">
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Key</th>
                            <th class="px-4 py-2 font-medium">Provider</th>
                            <th class="px-4 py-2 font-medium">Scope</th>
                            <th class="px-4 py-2 font-medium">Lang</th>
                            <th class="px-4 py-2 font-medium">Feed URL</th>
                            <th class="px-4 py-2 font-medium">Homepage</th>
                            <th class="px-4 py-2 text-right font-medium">Items</th>
                            <th class="px-4 py-2 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <tr v-for="source in sources" :key="source.id" class="hover:bg-accent">
                            <td class="px-4 py-2">
                                <div class="font-medium text-foreground">{{ source.name }}</div>
                                <div v-if="!source.is_active" class="mt-0.5 text-xs text-muted-foreground">Inactive</div>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs text-muted-foreground">{{ source.key }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ source.provider ?? '—' }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ scopeLabel(source.market) }}</td>
                            <td class="px-4 py-2 uppercase text-muted-foreground">{{ source.language ?? '—' }}</td>
                            <td class="max-w-[18rem] px-4 py-2">
                                <a
                                    v-if="source.feed_url"
                                    :href="source.feed_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="block truncate text-muted-foreground hover:text-foreground hover:underline"
                                >
                                    {{ source.feed_url }}
                                </a>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="max-w-[14rem] px-4 py-2">
                                <a
                                    v-if="source.homepage_url"
                                    :href="source.homepage_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="block truncate text-muted-foreground hover:text-foreground hover:underline"
                                >
                                    {{ source.homepage_url }}
                                </a>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">{{ source.news_items_count }}</td>
                            <td class="px-4 py-2">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        type="button"
                                        size="sm"
                                        :variant="source.is_active ? 'secondary' : 'outline'"
                                        :title="source.is_active ? 'Deactivate source' : 'Activate source'"
                                        @click="toggle(source)"
                                    >
                                        <Power class="size-4" />
                                        {{ source.is_active ? 'Active' : 'Inactive' }}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        variant="ghost"
                                        title="Edit RSS source"
                                        :disabled="!source.is_rss"
                                        @click="edit(source)"
                                    >
                                        <Pencil class="size-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        variant="ghost"
                                        title="Deactivate RSS source"
                                        :disabled="!source.is_rss || !source.is_active"
                                        @click="deactivate(source)"
                                    >
                                        <X class="size-4" />
                                        <span class="sr-only">Deactivate</span>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="sources.length === 0">
                            <td colspan="9" class="px-4 py-8 text-center text-sm text-muted-foreground">No news sources configured.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
