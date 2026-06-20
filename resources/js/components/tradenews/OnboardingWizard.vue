<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import OnboardingController from '@/actions/App/Http/Controllers/OnboardingController';
import { Button } from '@/components/ui/button';
import type { NewsSourcePref, SelectOption } from '@/types';

const props = defineProps<{
    sources: NewsSourcePref[];
    markets: SelectOption[];
}>();

type SourcePayload = {
    id: number;
    enabled: boolean;
};

const page = usePage();
const { locale, t } = useI18n();
const step = ref(1);

const browserLocale =
    typeof navigator !== 'undefined' && navigator.language.toLowerCase().startsWith('tr')
        ? 'tr'
        : 'en';

const initialLocale = page.props.locale === 'tr' ? 'tr' : browserLocale;
const initialMarkets = Array.isArray(page.props.dataPreferences?.preferred_markets)
    ? (page.props.dataPreferences.preferred_markets as string[])
    : [];

const form = useForm({
    locale: initialLocale,
    preferred_markets: initialMarkets,
    news_sources: props.sources.map((source): SourcePayload => ({
        id: source.id,
        enabled: source.enabled,
    })),
});

watch(
    () => form.locale,
    (value) => {
        locale.value = value === 'tr' ? 'tr' : 'en';
    },
    { immediate: true },
);

const activeSourceCount = computed(
    () => form.news_sources.filter((source) => source.enabled).length,
);

function setSourceEnabled(sourceId: number, enabled: boolean) {
    const source = form.news_sources.find((item) => item.id === sourceId);

    if (source) {
        source.enabled = enabled;
    }
}

function sourceEnabled(sourceId: number): boolean {
    return form.news_sources.find((source) => source.id === sourceId)?.enabled ?? false;
}

function toggleMarket(market: string, checked: boolean) {
    if (checked && !form.preferred_markets.includes(market)) {
        form.preferred_markets.push(market);
    }

    if (!checked) {
        form.preferred_markets = form.preferred_markets.filter((item) => item !== market);
    }
}

function finish() {
    form.put(OnboardingController.update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            step.value = 4;
        },
    });
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-background/80 p-4 backdrop-blur-sm">
        <section class="w-full max-w-xl rounded-lg border border-sidebar-border bg-card shadow-xl">
            <header class="border-b border-sidebar-border px-5 py-4">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    {{ step }} / 4
                </p>
                <h2 class="mt-1 text-lg font-semibold text-foreground">{{ t('onboarding.title') }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">{{ t('onboarding.description') }}</p>
            </header>

            <div class="px-5 py-5">
                <div v-if="step === 1" class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-foreground">{{ t('onboarding.languageTitle') }}</h3>
                        <p class="mt-1 text-sm text-muted-foreground">{{ t('onboarding.languageDescription') }}</p>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            class="cursor-pointer rounded-md border p-3 text-sm transition-colors"
                            :class="form.locale === 'en' ? 'border-foreground bg-muted' : 'border-sidebar-border hover:bg-accent/60'"
                        >
                            <input v-model="form.locale" class="sr-only" type="radio" value="en" />
                            {{ t('onboarding.english') }}
                        </label>
                        <label
                            class="cursor-pointer rounded-md border p-3 text-sm transition-colors"
                            :class="form.locale === 'tr' ? 'border-foreground bg-muted' : 'border-sidebar-border hover:bg-accent/60'"
                        >
                            <input v-model="form.locale" class="sr-only" type="radio" value="tr" />
                            {{ t('onboarding.turkish') }}
                        </label>
                    </div>
                </div>

                <div v-else-if="step === 2" class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-foreground">{{ t('onboarding.newsTitle') }}</h3>
                        <p class="mt-1 text-sm text-muted-foreground">{{ t('onboarding.newsDescription') }}</p>
                    </div>
                    <div class="max-h-72 overflow-y-auto rounded-md border border-sidebar-border p-2">
                        <label
                            v-for="source in sources"
                            :key="source.id"
                            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-2 text-sm hover:bg-accent/60"
                        >
                            <input
                                type="checkbox"
                                class="size-4 rounded border-sidebar-border text-foreground"
                                :checked="sourceEnabled(source.id)"
                                @change="setSourceEnabled(source.id, ($event.target as HTMLInputElement).checked)"
                            />
                            <span class="min-w-0 flex-1 truncate text-foreground">{{ source.name }}</span>
                            <span
                                v-if="source.language"
                                class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground"
                            >
                                {{ source.language }}
                            </span>
                        </label>
                        <p v-if="!sources.length" class="p-2 text-sm text-muted-foreground">
                            {{ t('common.noSources') }}
                        </p>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ activeSourceCount }} / {{ sources.length }}
                    </p>
                </div>

                <div v-else-if="step === 3" class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-foreground">{{ t('onboarding.marketsTitle') }}</h3>
                        <p class="mt-1 text-sm text-muted-foreground">{{ t('onboarding.marketsDescription') }}</p>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="market in markets"
                            :key="market.value"
                            class="flex cursor-pointer items-center gap-2 rounded-md border border-sidebar-border p-3 text-sm hover:bg-accent/60"
                        >
                            <input
                                type="checkbox"
                                class="size-4 rounded border-sidebar-border text-foreground"
                                :checked="form.preferred_markets.includes(String(market.value))"
                                @change="toggleMarket(String(market.value), ($event.target as HTMLInputElement).checked)"
                            />
                            <span>{{ market.label }}</span>
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ form.preferred_markets.length === 0 ? t('onboarding.allMarkets') : form.preferred_markets.join(', ') }}
                    </p>
                </div>

                <div v-else class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-foreground">{{ t('onboarding.tipsTitle') }}</h3>
                        <p class="mt-1 text-sm text-muted-foreground">{{ t('onboarding.tipsDescription') }}</p>
                    </div>
                    <ul class="space-y-2 text-sm text-muted-foreground">
                        <li class="rounded-md bg-muted p-3">{{ t('onboarding.tipAlerts') }}</li>
                        <li class="rounded-md bg-muted p-3">{{ t('onboarding.tipWatchlist') }}</li>
                        <li class="rounded-md bg-muted p-3">{{ t('onboarding.tipSources') }}</li>
                        <li class="rounded-md bg-muted p-3">{{ t('onboarding.tipAi') }}</li>
                    </ul>
                </div>
            </div>

            <footer class="flex items-center justify-between border-t border-sidebar-border px-5 py-4">
                <Button type="button" variant="outline" :disabled="step === 1 || form.processing" @click="step -= 1">
                    {{ t('common.back') }}
                </Button>
                <Button v-if="step < 4" type="button" :disabled="form.processing" @click="step += 1">
                    {{ t('common.next') }}
                </Button>
                <Button v-else type="button" :disabled="form.processing" @click="finish">
                    {{ form.processing ? t('common.saving') : t('common.finish') }}
                </Button>
            </footer>
        </section>
    </div>
</template>
