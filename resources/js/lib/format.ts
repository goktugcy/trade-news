const currencySymbols: Record<string, string> = {
    USD: '$',
    TRY: '₺',
    EUR: '€',
};

export function formatPrice(value: number | null | undefined, currency = 'USD'): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const symbol = currencySymbols[currency] ?? '';
    const formatted = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);

    return `${symbol}${formatted}`;
}

export function formatChange(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const sign = value > 0 ? '+' : '';

    return `${sign}${value.toFixed(2)}`;
}

export function formatPercent(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const sign = value > 0 ? '+' : '';

    return `${sign}${value.toFixed(2)}%`;
}

export function formatNumber(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}

export function changeClass(value: number | null | undefined): string {
    if (value === null || value === undefined || value === 0) {
        return 'text-muted-foreground';
    }

    return value > 0
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-rose-600 dark:text-rose-400';
}
