import { NotificationRowContent, openNotification } from '@/components/notification-bell';
import { PageContent, PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import { JoinPagination } from '@/components/ui/join-pagination';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type NotificationFilter, type NotificationPage } from '@/types/notifications';
import { Head, router, usePage } from '@inertiajs/react';
import { BellOff, CheckCheck, FilterX } from 'lucide-react';

interface Props {
    notifications: NotificationPage;
    filter: NotificationFilter;
}

const FILTERS: { value: NotificationFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'action', label: 'Action' },
    { value: 'unread', label: 'Unread' },
];

const EMPTY: Record<NotificationFilter, string> = {
    all: "You're all caught up.",
    action: 'Nothing needs your action.',
    unread: "You're all caught up. Nothing is unread.",
};

/**
 * NOTIF-3: "View all" (Blueprint system notification center). The signed-in
 * user's own notifications, newest first, 20 a page. Filter: All, Action
 * (something for this user to do) or Unread, kept in ?filter=. A row marks
 * itself read and opens its case; "Mark all as read" clears the count.
 */
export default function NotificationsIndex({ notifications, filter }: Props) {
    const unread = usePage<SharedData>().props.unreadNotificationsCount;
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notifications', href: route('notifications.index') }];

    const choose = (next: NotificationFilter) =>
        router.get(route('notifications.index'), next === 'all' ? {} : { filter: next }, { preserveScroll: true, preserveState: true });

    const markAllRead = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />

            <PageContent>
                <PageHeader
                    title="Notifications"
                    subtitle={unread === 0 ? 'Nothing unread.' : `${unread} unread.`}
                    actions={
                        <button type="button" className="btn btn-sm capitalize" disabled={unread === 0} onClick={markAllRead}>
                            <CheckCheck className="size-4" aria-hidden="true" />
                            Mark all as read
                        </button>
                    }
                />

                <FlashMessages />

                <section aria-labelledby="notifications-list-heading" className="card bg-base-100 border-base-300 min-w-0 border">
                    <div className="border-base-300 flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <h2 id="notifications-list-heading" className="text-base font-semibold capitalize">
                            {FILTERS.find((f) => f.value === filter)?.label ?? 'All'} notifications
                        </h2>
                        {/* A segmented toggle (aria-pressed), not radios: RadioGuardTest keeps radios to the shared RadioGroup. */}
                        <div role="group" aria-label="Show notifications" className="join">
                            {FILTERS.map((f) => (
                                <button
                                    key={f.value}
                                    type="button"
                                    className={cn('join-item btn btn-sm capitalize', filter === f.value && 'btn-active')}
                                    aria-pressed={filter === f.value}
                                    onClick={() => filter !== f.value && choose(f.value)}
                                >
                                    {f.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {notifications.data.length === 0 ? (
                        <div className="text-base-content/70 flex flex-col items-center gap-3 px-4 py-14 text-center text-sm">
                            <BellOff className="text-base-content/40 size-6" aria-hidden="true" />
                            <p>{EMPTY[filter]}</p>
                            {filter !== 'all' && (
                                <button type="button" className="btn btn-ghost btn-sm capitalize" onClick={() => choose('all')}>
                                    <FilterX className="size-4" aria-hidden="true" />
                                    Show all notifications
                                </button>
                            )}
                        </div>
                    ) : (
                        <ul className="list" aria-label="Notifications">
                            {notifications.data.map((row) => (
                                <li key={row.id} className="border-base-300 border-b last:border-b-0">
                                    <button
                                        type="button"
                                        className={cn(
                                            'hover:bg-base-200 focus-visible:bg-base-200 flex w-full items-start gap-3 px-4 py-3 outline-none motion-safe:transition-colors',
                                            !row.read && 'bg-base-200/50',
                                        )}
                                        disabled={!row.url}
                                        onClick={() => void openNotification(row)}
                                    >
                                        <NotificationRowContent row={row} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {notifications.total > 0 && (
                        <JoinPagination
                            label="Notification pages"
                            from={notifications.from}
                            to={notifications.to}
                            total={notifications.total}
                            prevUrl={notifications.prev_page_url}
                            nextUrl={notifications.next_page_url}
                        />
                    )}
                </section>
            </PageContent>
        </AppLayout>
    );
}
