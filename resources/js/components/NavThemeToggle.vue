<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/types';

const { appearance, updateAppearance } = useAppearance();

// Cycle light → dark → system → light.
const order: Appearance[] = ['light', 'dark', 'system'];

const meta = computed(() => {
    switch (appearance.value) {
        case 'dark':
            return { icon: Moon, label: 'Dark mode' };
        case 'system':
            return { icon: Monitor, label: 'System theme' };
        default:
            return { icon: Sun, label: 'Light mode' };
    }
});

function cycle() {
    const next = order[(order.indexOf(appearance.value) + 1) % order.length];
    updateAppearance(next);
}
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <SidebarMenuButton :tooltip="meta.label" @click="cycle">
                <component :is="meta.icon" />
                <span>{{ meta.label }}</span>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
