import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

export type InboxItem = {
    id: number;
    category: string;
    category_color: string;
    type: string;
    title: string;
    body: string | null;
    action_url: string | null;
    is_read: boolean;
    created_at: string | null;
};

/**
 * Polls the lightweight unread endpoint on the user's auto-refresh cadence
 * (0 = disabled). Seeds the count from the shared `notifications.unread_count`
 * prop so the badge is correct on first paint.
 */
export function useNotificationsPoll(): {
    count: Ref<number>;
    items: Ref<InboxItem[]>;
    refresh: () => Promise<void>;
} {
    const page = usePage();
    const count = ref<number>((page.props.notifications as { unread_count?: number } | undefined)?.unread_count ?? 0);
    const items = ref<InboxItem[]>([]);
    let timer: ReturnType<typeof setInterval> | undefined;

    async function refresh(): Promise<void> {
        try {
            const res = await fetch('/notifications/unread', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                return;
            }
            const json = await res.json();
            count.value = json.count ?? 0;
            items.value = json.items ?? [];
        } catch {
            // network hiccup — keep the last known values
        }
    }

    onMounted(() => {
        refresh();
        const seconds = Number((page.props.dataPreferences as { auto_refresh_seconds?: number } | undefined)?.auto_refresh_seconds ?? 0);
        if (seconds > 0) {
            timer = setInterval(refresh, seconds * 1000);
        }
    });

    onBeforeUnmount(() => {
        if (timer) {
            clearInterval(timer);
        }
    });

    return { count, items, refresh };
}
