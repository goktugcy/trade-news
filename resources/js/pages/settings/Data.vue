<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import DataPreferenceController from '@/actions/App/Http/Controllers/Settings/DataPreferenceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/data-preferences';

type Preference = {
    auto_refresh_seconds: number;
};

type AutoRefreshOption = {
    value: number;
    label: string;
};

defineProps<{
    preference: Preference;
    autoRefreshOptions: AutoRefreshOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Data settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Data settings" />

    <h1 class="sr-only">Data settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Data"
            description="Set browser refresh behavior for live market views"
        />

        <Form
            v-bind="DataPreferenceController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="auto_refresh_seconds">Auto refresh</Label>
                <select
                    id="auto_refresh_seconds"
                    name="auto_refresh_seconds"
                    :value="preference.auto_refresh_seconds"
                    class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                >
                    <option
                        v-for="option in autoRefreshOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <InputError class="mt-2" :message="errors.auto_refresh_seconds" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing">Save</Button>
            </div>
        </Form>
    </div>
</template>
