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

/**
 * The id of the top (newest) item on screen. The feed is rendered newest-first
 * (published_at desc, id desc), so the first element is the cursor anchor — the
 * server reads its exact timestamp and returns only rows strictly above it.
 */
function topId(items: NewsCardData[]): number {
    return items[0]?.id ?? 0;
}

/**
 * Twitter-style live feed over polling. The cursor is the top item's id; the
 * server resolves its exact (microsecond) timestamp and returns only genuinely
 * newer rows, so items already shown are never re-offered after a refresh.
 * `pending` is recomputed each poll as "newer than the anchor, not on screen".
 *
 * While the reader is at the top, new items stream in automatically; once they
 * scroll down they buffer behind the "+N new" pill so the list never jumps.
 */
export function useLiveNewsFeed(opts: Options): {
    items: Ref<NewsCardData[]>;
    pendingCount: ComputedRef<number>;
    flush: () => void;
} {
    const items = ref<NewsCardData[]>([...opts.initial()]);
    const pending = ref<NewsCardData[]>([]);
    const pendingCount = computed(() => pending.value.length);

    // Cursor: id of the newest item on screen. Anything strictly newer is "new".
    let cursorId = topId(items.value);

    // New SSR data (navigation / filter change) resets the visible list + cursor.
    watch(opts.initial, (next) => {
        items.value = [...next];
        pending.value = [];
        cursorId = topId(next);
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
            const params = new URLSearchParams({ scope: opts.scope });
            if (cursorId > 0) {
                params.set('after_id', String(cursorId));
            }
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

            // The server returns the full "newer than the anchor" set (newest
            // first); keep only the ones not already on screen as the buffer.
            const shown = new Set(items.value.map((item) => item.id));
            pending.value = (json.items ?? []).filter((item) => !shown.has(item.id));

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

        items.value = [...pending.value, ...items.value];
        pending.value = [];

        // Advance the cursor to the new top so revealed items aren't re-offered.
        cursorId = topId(items.value);
    }

    return { items, pendingCount, flush };
}
