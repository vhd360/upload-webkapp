export function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) {
        return '0 B';
    }

    if (bytes < 1024) {
        return `${Math.round(bytes)} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    const digits = value >= 10 ? 0 : 1;

    return `${new Intl.NumberFormat('de-DE', { maximumFractionDigits: digits }).format(value)} ${units[unit]}`;
}

export function formatDuration(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '';
    }

    const total = Math.round(seconds);

    if (total < 60) {
        return `${total} Sek.`;
    }

    const minutes = Math.floor(total / 60);
    const rest = total % 60;

    if (minutes < 60) {
        return rest > 0 ? `${minutes} Min. ${rest} Sek.` : `${minutes} Min.`;
    }

    const hours = Math.floor(minutes / 60);

    return `${hours} Std. ${minutes % 60} Min.`;
}
