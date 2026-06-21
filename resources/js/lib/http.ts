function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * POST JSON to a same-origin Laravel route and return the parsed JSON.
 * Sends the XSRF-TOKEN cookie back as the X-XSRF-TOKEN header (same mechanism
 * Inertia/axios uses) so the web CSRF middleware is satisfied.
 */
export async function postJson<T>(url: string, body: Record<string, unknown> = {}): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!res.ok) {
        throw new Error(`Request failed with status ${res.status}`);
    }

    return (await res.json()) as T;
}
