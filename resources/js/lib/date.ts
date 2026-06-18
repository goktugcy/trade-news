// All timestamps from the backend are ISO-8601 UTC. These helpers render them
// in an explicit IANA timezone (the user's preferred zone) using the platform
// Intl APIs — no extra dependency.

const DEFAULT_TZ = 'Europe/Istanbul';

function safeTz(timezone?: string | null): string {
    return timezone && timezone.length > 0 ? timezone : DEFAULT_TZ;
}

/** e.g. "17 Jun 2026, 14:30" in the given timezone. */
export function formatDateTime(iso: string | null | undefined, timezone?: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: safeTz(timezone),
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

/** e.g. "14:30" in the given timezone. */
export function formatTime(iso: string | null | undefined, timezone?: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: safeTz(timezone),
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

/** Relative time like "3 hours ago" / "in 2 days" (timezone-independent). */
export function relativeTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const diffMs = new Date(iso).getTime() - Date.now();
    const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

    const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
        ['year', 31_536_000_000],
        ['month', 2_592_000_000],
        ['day', 86_400_000],
        ['hour', 3_600_000],
        ['minute', 60_000],
    ];

    for (const [unit, ms] of units) {
        if (Math.abs(diffMs) >= ms || unit === 'minute') {
            return rtf.format(Math.round(diffMs / ms), unit);
        }
    }

    return 'just now';
}
