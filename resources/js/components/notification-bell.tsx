import { useUnreadCount } from '@/hooks/use-unread-count';
import { badgeText, bellApi, bellLabel, fullTime, isAuthStop, relativeTime } from '@/lib/notifications';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type NotificationRow } from '@/types/notifications';
import { Link, router, usePage } from '@inertiajs/react';
import { Bell, BellOff, CheckCheck, CircleAlert } from 'lucide-react';
import { type CSSProperties, type KeyboardEvent, type ToggleEvent, useRef, useState } from 'react';

const POPOVER_ID = 'notifications-dropdown';
const ANCHOR = '--notifications-bell';

/**
 * One bell row's content: title, case reference, relative time (Port of
 * Spain), an "Action" badge when the user has something to do, and unread
 * rows emphasised (a dot and semibold title, plus "Unread" for screen
 * readers, so colour is never the only signal). Shared by the header
 * dropdown and the notifications page.
 */
export function NotificationRowContent({ row }: { row: NotificationRow }) {
    return (
        <>
            <span className="flex h-5 w-2 shrink-0 items-center" aria-hidden="true">
                {!row.read && <span className="status status-primary status-md" />}
            </span>
            <span className="grid min-w-0 flex-1 gap-0.5 text-start">
                <span className={cn('text-sm', row.read ? 'text-base-content/80' : 'font-semibold')}>
                    {!row.read && <span className="sr-only">Unread: </span>}
                    {row.title ?? 'Notification'}
                </span>
                <span className="text-base-content/60 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    {row.reference && <span className="font-mono font-medium">{row.reference}</span>}
                    {row.created_at && (
                        <time dateTime={row.created_at} title={fullTime(row.created_at)} className="tabular-nums">
                            {relativeTime(row.created_at)}
                        </time>
                    )}
                    {row.kind === 'action' && <span className="badge badge-soft badge-warning badge-xs capitalize">Action</span>}
                </span>
            </span>
        </>
    );
}

/**
 * Open a bell row: mark it read, then visit its page. A failed read still
 * opens the page (the page marks its own case's notifications read anyway).
 */
export async function openNotification(row: NotificationRow, onCount?: (count: number) => void): Promise<void> {
    try {
        const count = await bellApi.read(row.id);
        onCount?.(count);
    } catch {
        // The visit below still happens.
    }
    if (row.url) {
        router.visit(row.url);
    }
}

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

/**
 * NOTIF-3: the header bell (staff and vendors). Blueprint: an indicator badge
 * on a ghost circle button, opening a popover dropdown (card) with the latest
 * 10. Esc closes, arrow keys move through the rows, and focus goes back to the
 * bell on close.
 */
export function NotificationBell() {
    const shared = usePage<SharedData>().props.unreadNotificationsCount;
    const { count, setCount } = useUnreadCount(typeof shared === 'number' ? shared : 0);
    const [rows, setRows] = useState<NotificationRow[]>([]);
    const [state, setState] = useState<LoadState>('idle');
    const [open, setOpen] = useState(false);
    const bellRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    const rowButtons = () => Array.from(panelRef.current?.querySelectorAll<HTMLButtonElement>('[data-bell-row]') ?? []);

    const close = (focusBell = true) => {
        panelRef.current?.hidePopover();
        if (focusBell) {
            bellRef.current?.focus();
        }
    };

    const load = async () => {
        setState('loading');
        try {
            const latest = await bellApi.latest();
            setRows(latest.data);
            setCount(latest.unread);
            setState('ready');
            // Keyboard users land on the first row; the arrow keys take it from there.
            window.requestAnimationFrame(() => rowButtons()[0]?.focus());
        } catch (error) {
            setState(isAuthStop(error) ? 'idle' : 'error');
            if (isAuthStop(error)) {
                window.location.reload();
            }
        }
    };

    const onToggle = (e: ToggleEvent<HTMLDivElement>) => {
        const isOpen = e.newState === 'open';
        setOpen(isOpen);
        if (isOpen) {
            void load();
        } else if (!document.activeElement || document.activeElement === document.body || panelRef.current?.contains(document.activeElement)) {
            // Closed by Esc or light dismiss with nothing else focused: back to the bell.
            bellRef.current?.focus();
        }
    };

    const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            close();
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
            return;
        }
        const buttons = rowButtons();
        if (buttons.length === 0) {
            return;
        }
        e.preventDefault();
        const at = buttons.indexOf(document.activeElement as HTMLButtonElement);
        const next =
            e.key === 'Home'
                ? 0
                : e.key === 'End'
                  ? buttons.length - 1
                  : e.key === 'ArrowDown'
                    ? (at + 1) % buttons.length
                    : (at - 1 + buttons.length) % buttons.length;
        buttons[next].focus();
    };

    const markAllRead = async () => {
        try {
            setCount(await bellApi.readAll());
            setRows((current) => current.map((row) => ({ ...row, read: true })));
        } catch (error) {
            if (isAuthStop(error)) {
                window.location.reload();
            }
        }
    };

    return (
        <>
            <button
                ref={bellRef}
                type="button"
                className="btn btn-ghost btn-circle btn-sm motion-safe:transition-transform motion-safe:active:scale-95"
                popoverTarget={POPOVER_ID}
                style={{ anchorName: ANCHOR } as CSSProperties}
                aria-label={bellLabel(count)}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-controls={POPOVER_ID}
            >
                <span className="indicator">
                    <Bell className="size-5" aria-hidden="true" />
                    {count > 0 && (
                        <span data-bell-badge className="indicator-item badge badge-primary badge-xs px-1 tabular-nums" aria-hidden="true">
                            {badgeText(count)}
                        </span>
                    )}
                </span>
            </button>

            <div
                ref={panelRef}
                id={POPOVER_ID}
                popover="auto"
                role="dialog"
                aria-label="Notifications"
                onToggle={onToggle}
                onKeyDown={onKeyDown}
                className="dropdown dropdown-end card card-sm bg-base-100 border-base-300 mt-2 w-[min(24rem,calc(100vw-2rem))] border shadow-md"
                style={{ positionAnchor: ANCHOR } as CSSProperties}
            >
                <div className="border-base-300 flex items-center justify-between gap-2 border-b px-4 py-3">
                    <h2 className="text-sm font-semibold capitalize">Notifications</h2>
                    <span className="text-base-content/60 text-xs tabular-nums" aria-live="polite">
                        {count === 0 ? 'None unread' : `${count} unread`}
                    </span>
                </div>

                <div className="max-h-[min(28rem,70vh)] overflow-y-auto" aria-busy={state === 'loading'}>
                    {state === 'loading' && rows.length === 0 && (
                        <ul className="list" aria-hidden="true">
                            {[0, 1, 2].map((i) => (
                                <li key={i} className="list-row items-start gap-3 px-4 py-3">
                                    <span className="skeleton size-2 rounded-full" />
                                    <span className="grid flex-1 gap-2">
                                        <span className="skeleton h-4 w-4/5" />
                                        <span className="skeleton h-3 w-1/2" />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    {state === 'error' && (
                        <div role="alert" className="alert alert-soft alert-error m-3 text-sm">
                            <CircleAlert className="size-4" aria-hidden="true" />
                            <span>Notifications could not load.</span>
                            <button type="button" className="btn btn-sm capitalize" onClick={() => void load()}>
                                Try again
                            </button>
                        </div>
                    )}

                    {state === 'ready' && rows.length === 0 && (
                        <p className="text-base-content/70 flex flex-col items-center gap-2 px-4 py-10 text-center text-sm">
                            <BellOff className="text-base-content/40 size-6" aria-hidden="true" />
                            You're all caught up.
                        </p>
                    )}

                    {rows.length > 0 && state !== 'error' && (
                        <ul className="list" aria-label="Latest notifications">
                            {rows.map((row) => (
                                <li key={row.id} className="border-base-300 border-b last:border-b-0">
                                    <button
                                        type="button"
                                        data-bell-row
                                        className={cn(
                                            'hover:bg-base-200 focus-visible:bg-base-200 flex w-full items-start gap-3 px-4 py-3 outline-none motion-safe:transition-colors',
                                            !row.read && 'bg-base-200/50',
                                        )}
                                        onClick={() => {
                                            close(false);
                                            void openNotification(row, setCount);
                                        }}
                                    >
                                        <NotificationRowContent row={row} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="border-base-300 flex items-center justify-between gap-2 border-t px-3 py-2">
                    <button type="button" className="btn btn-ghost btn-sm capitalize" disabled={count === 0} onClick={() => void markAllRead()}>
                        <CheckCheck className="size-4" aria-hidden="true" />
                        Mark all as read
                    </button>
                    <Link href={route('notifications.index')} className="btn btn-sm capitalize" onClick={() => close(false)}>
                        View all
                    </Link>
                </div>
            </div>
        </>
    );
}
