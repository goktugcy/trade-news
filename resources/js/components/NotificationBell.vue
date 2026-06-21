<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from 'vue-i18n';
import { useNotificationsPoll } from '@/composables/useNotificationsPoll';
import { useUserTimezone } from '@/composables/useUserTimezone';

const { count, items, refresh } = useNotificationsPoll();
const { relative } = useUserTimezone();
const { t } = useI18n();

function markAllRead() {
    router.post('/notifications/read-all', {}, { preserveScroll: true, onSuccess: () => refresh() });
}

function open(id: number, actionUrl: string | null) {
    router.patch(`/notifications/${id}/read`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            refresh();
            if (actionUrl) {
                router.visit(actionUrl);
            }
        },
    });
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            class="relative inline-flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            :aria-label="t('notifications.title')"
        >
            <Bell class="size-5" />
            <span
                v-if="count > 0"
                class="absolute -right-0.5 -top-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold text-white"
            >{{ count > 99 ? '99+' : count }}</span>
        </DropdownMenuTrigger>

        <DropdownMenuContent class="w-80" align="end">
            <div class="flex items-center justify-between px-2 py-1.5">
                <DropdownMenuLabel class="p-0">{{ t('notifications.title') }}</DropdownMenuLabel>
                <button
                    v-if="count > 0"
                    type="button"
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-3.5" /> {{ t('notifications.markAllRead') }}
                </button>
            </div>
            <DropdownMenuSeparator />

            <div v-if="items.length === 0" class="px-3 py-6 text-center text-sm text-muted-foreground">
                {{ t('notifications.allCaughtUp') }}
            </div>

            <ul v-else class="max-h-80 overflow-y-auto">
                <li v-for="item in items" :key="item.id">
                    <button
                        type="button"
                        class="flex w-full flex-col gap-0.5 px-3 py-2 text-left transition-colors hover:bg-accent"
                        :class="!item.is_read ? 'bg-accent/40' : ''"
                        @click="open(item.id, item.action_url)"
                    >
                        <div class="flex items-center gap-2">
                            <span v-if="!item.is_read" class="size-1.5 shrink-0 rounded-full bg-sky-500" />
                            <span class="line-clamp-1 text-sm font-medium text-foreground">{{ item.title }}</span>
                        </div>
                        <span v-if="item.body" class="line-clamp-2 text-xs text-muted-foreground">{{ item.body }}</span>
                        <span class="text-[11px] text-muted-foreground">{{ relative(item.created_at) }}</span>
                    </button>
                </li>
            </ul>

            <DropdownMenuSeparator />
            <Link
                href="/notifications"
                class="block px-3 py-2 text-center text-sm font-medium text-foreground hover:bg-accent"
            >
                {{ t('notifications.viewAll') }}
            </Link>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
