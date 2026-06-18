import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';
import { formatDateTime, formatTime, relativeTime } from '@/lib/date';

/**
 * Resolves the authenticated user's preferred timezone (default Europe/Istanbul)
 * and exposes timezone-bound formatting helpers for templates.
 */
export function useUserTimezone(): {
    timezone: ComputedRef<string>;
    dateTime: (iso: string | null | undefined) => string;
    time: (iso: string | null | undefined) => string;
    relative: (iso: string | null | undefined) => string;
} {
    const page = usePage();

    const timezone = computed<string>(
        () => (page.props.auth?.user?.timezone as string | undefined) ?? 'Europe/Istanbul',
    );

    return {
        timezone,
        dateTime: (iso) => formatDateTime(iso, timezone.value),
        time: (iso) => formatTime(iso, timezone.value),
        relative: (iso) => relativeTime(iso),
    };
}
