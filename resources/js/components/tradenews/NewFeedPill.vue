<script setup lang="ts">
import { ArrowUp } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

defineProps<{ count: number }>();
const emit = defineEmits<{ (e: 'reveal'): void }>();

const { t } = useI18n();
</script>

<template>
    <Transition name="pill">
        <div v-if="count > 0" class="pointer-events-none sticky top-2 z-10 flex justify-center">
            <button
                type="button"
                class="pointer-events-auto inline-flex items-center gap-1.5 rounded-full border border-sidebar-border/70 bg-primary px-3.5 py-1.5 text-xs font-semibold text-primary-foreground shadow-lg transition-transform hover:scale-[1.03] dark:border-sidebar-border"
                @click="emit('reveal')"
            >
                <ArrowUp class="size-3.5" />
                {{ t('news.newItems', { count }) }}
            </button>
        </div>
    </Transition>
</template>

<style scoped>
.pill-enter-active,
.pill-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}

.pill-enter-from,
.pill-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>
