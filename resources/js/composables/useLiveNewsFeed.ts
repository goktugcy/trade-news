import { computed, ref, watch, type ComputedRef, type Ref } from 'vue';
import { useVisibilityAwarePoll } from '@/composables/useVisibilityAwarePoll';
import type { LiveNewsResponse, NewsCardData, NewsFeedScope } from '@/types';

type FilterMap = Record<string, string | null | undefined>;

type Options = {
    scope: NewsFeedScope;
    initial: () => NewsCardData[];
    filters?: () => FilterMap;
    intervalMs?: number;
};

function maxId(items: NewsCardData[]): number {
    return items.reduce((max, item) => (item.id > max ? item.id : max), 0);
}

/**
 * Twitter-style live feed over polling. A persisted "seen" high-water-mark (by
 * id, which is monotonic) guarantees that items the user has already loaded —
 * via SSR or by tapping the pill — never come back as "new" after a refresh.
 * Updates to already-visible cards (translation, sentiment, counts) merge in place.
 */
export function useLiveNewsFeed(opts: Options): {
    items: Ref<NewsCardData[]>;
    pendingCount: ComputedRef<number>;
    flush: () => void;
} {
    const storageKey = `tn:seen:${opts.scope}:${JSON.stringify(opts.filters?.() ?? {})}`;

    function readSeen(): number {
        try {
            return Number(window.localStorage.getItem(storageKey) ?? 0) || 0;
        } catch {
            return 0;
        }
    }

    function writeSeen(id: number): void {
        try {
            window.localStorage.setItem(storageKey, String(id));
        } catch {
            // storage unavailable — fall back to in-memory cursor only
        }
    }

    const items = ref<NewsCardData[]>([...opts.initial()]);
    const pending = ref<NewsCardData[]>([]);
    const pendingCount = computed(() => pending.value.length);

    // SSR items are "seen"; never offer anything at or below this id again.
    let seen = Math.max(maxId(items.value), readSeen());
    writeSeen(seen);

    // New SSR data (navigation / filter change) resets the visible list.
    watch(opts.initial, (next) => {
        items.value = [...next];
        pending.value = [];
        seen = Math.max(seen, maxId(next));
        writeSeen(seen);
    });

    function applyUpdates(updates: NewsCardData[]): void {
        for (const update of updates) {
            const visible = items.value.findIndex((item) => item.id === update.id);
            if (visible >= 0) {
                items.value[visible] = { ...items.value[visible], ...update };
            }

            const buffered = pending.value.findIndex((item) => item.id === update.id);
            if (buffered >= 0) {
                pending.value[buffered] = { ...pending.value[buffered], ...update };
            }
        }
    }

    async function poll(): Promise<void> {
        try {
            const params = new URLSearchParams({ scope: opts.scope, after: String(seen) });
            params.set('ids', items.value.slice(0, 60).map((item) => item.id).join(','));

            const filters = opts.filters?.() ?? {};
            for (const [key, value] of Object.entries(filters)) {
                if (value !== null && value !== undefined && value !== '') {
                    params.set(key, value);
                }
            }

            const res = await fetch(`/news/live?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!res.ok) {
                return;
            }

            const json = (await res.json()) as LiveNewsResponse;

            if (json.updates?.length) {
                applyUpdates(json.updates);
            }

            const known = new Set([...items.value, ...pending.value].map((item) => item.id));
            const fresh = (json.items ?? []).filter((item) => item.id > seen && !known.has(item.id));

            if (fresh.length) {
                pending.value = [...fresh, ...pending.value];
            }
        } catch {
            // network hiccup — keep last known values
        }
    }

    useVisibilityAwarePoll(poll, opts.intervalMs ?? 15000);

    function flush(): void {
        if (pending.value.length === 0) {
            return;
        }

        // Mark the revealed items as seen so a refresh won't re-offer them.
        seen = Math.max(seen, maxId(pending.value));
        writeSeen(seen);

        items.value = [...pending.value, ...items.value];
        pending.value = [];
    }

    return { items, pendingCount, flush };
}
