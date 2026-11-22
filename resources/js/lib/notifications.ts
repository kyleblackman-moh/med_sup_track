import { type NotificationRow } from '@/types/notifications';

const TZ = 'America/Port_of_Spain';

/** The badge text: the count, or "99+" above 99. */
export function badgeText(count: number): string {
    return count > 99 ? '99+' : String(count);
}

/** "Notifications, N unread" (the bell's accessible name). */
export function bellLabel(count: number): string {
    return `Notifications, ${count} unread`;
}

const relative = new Intl.RelativeTimeFormat('en-TT', { numeric: 'auto' });

/**
 * "5 minutes ago", "yesterday"; past a week, the date in Port of Spain time.
 * `now` is injectable for tests.
 */
export function relativeTime(iso: string | null, now: number = Date.now()): string {
    if (!iso) {
        return '';
    }
    const then = new Date(iso).getTime();
    const seconds = Math.round((then - now) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 60) {
        return 'just now';
    }
    if (abs < 3600) {
        return relative.format(Math.round(seconds / 60), 'minute');
    }
    if (abs < 86400) {
        return relative.format(Math.round(seconds / 3600), 'hour');
    }
    if (abs < 7 * 86400) {
        return relative.format(Math.round(seconds / 86400), 'day');
    }

    return new Date(iso).toLocaleDateString('en-TT', { timeZone: TZ, day: 'numeric', month: 'short', year: 'numeric' });
}

/** The full date and time in Port of Spain, for a <time> title. */
export function fullTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/** A fetch error that carries the HTTP status, so the poller can stop on 401/419. */
export class BellHttpError extends Error {
    constructor(public status: number) {
        super(`Notifications request failed: ${status}`);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function call<T>(url: string, method: 'GET' | 'POST'): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'POST' ? { 'X-XSRF-TOKEN': xsrfToken() } : {}),
        },
    });
    if (!response.ok) {
        throw new BellHttpError(response.status);
    }

    return (await response.json()) as T;
}

export const bellApi = {
    unreadCount: () => call<{ count: number }>(route('notifications.unread-count'), 'GET').then((r) => r.count),
    latest: () => call<{ data: NotificationRow[]; unread: number }>(route('notifications.latest'), 'GET'),
    read: (id: string) => call<{ count: number }>(route('notifications.read', id), 'POST').then((r) => r.count),
    readAll: () => call<{ count: number }>(route('notifications.read-all'), 'POST').then((r) => r.count),
};

/** Session gone (401) or CSRF token expired (419): stop polling. */
export function isAuthStop(error: unknown): boolean {
    return error instanceof BellHttpError && (error.status === 401 || error.status === 419);
}
