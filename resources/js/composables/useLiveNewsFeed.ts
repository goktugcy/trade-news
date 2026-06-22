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
 * Twitter-style live feed over polling. New items (id greater than what's on
 * screen) surface as a "+N new" pill the reader taps to prepend them.
 *
 * The high-water-mark is derived purely from the SSR-rendered feed (its newest
 * id), NOT persisted — the feed always renders newest-first, so the on-screen
 * max IS the correct cursor. (Persisting it broke polling whenever the dev DB
 * was reseeded to lower ids: the stale-high cursor made `id > seen` match
 * nothing while a full refresh still showed the new rows.)
 */
export function useLiveNewsFeed(opts: Options): {
    items: Ref<NewsCardData[]>;
    pendingCount: ComputedRef<number>;
    flush: () => void;
} {
    const items = ref<NewsCardData[]>([...opts.initial()]);
    const pending = ref<NewsCardData[]>([]);
    const pendingCount = computed(() => pending.value.length);

    // Cursor: highest id already on screen. Anything above it is "new".
    let seen = maxId(items.value);

    // New SSR data (navigation / filter change) resets the visible list + cursor.
    watch(opts.initial, (next) => {
        items.value = [...next];
        pending.value = [];
        seen = maxId(next);
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
                cache: 'no-store',
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

            // While the reader is at the top of the feed, new items stream in
            // automatically (no pill to dismiss). Once they scroll down, items
            // buffer behind the "+N new" pill so the list never jumps under them;
            // scrolling back to the top auto-reveals on the next poll.
            if (pending.value.length > 0 && isAtTop()) {
                flush();
            }
        } catch {
            // network hiccup — keep last known values
        }
    }

    function isAtTop(): boolean {
        return typeof window !== 'undefined' && window.scrollY < 200;
    }

    useVisibilityAwarePoll(poll, opts.intervalMs ?? 15000);

    function flush(): void {
        if (pending.value.length === 0) {
            return;
        }

        // Revealed items become the new high-water-mark so they aren't re-offered.
        seen = Math.max(seen, maxId(pending.value));

        items.value = [...pending.value, ...items.value];
        pending.value = [];
    }

    return { items, pendingCount, flush };
}
