import { RISE } from '@/components/dashboard/motion';
import { WorkQueue, type DashboardQueue, type WorkQueueData } from '@/components/dashboard/work-queue';
import { PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface Props {
    /** Every staff user's queue (empty without queue permissions); null only for a vendor. */
    workQueue: WorkQueueData | null;
    /** "3 tasks to complete, most recent first" (server); null without a queue. The greeting lives in the top bar. */
    summary: string | null;
    /** Case id => request type name for the queue's rows (sent by the server; DASH-1: the rows carry their own type_label). */
    requestTypes: Record<string, string>;
    /** The To Do card after the server applied the search; null without a queue. */
    queue: DashboardQueue | null;
}

/**
 * Kyle, 2026-11-26: the staff landing page is "To Do" (was Dashboard): only
 * the tasks that need the user's attention now, under who acts on them, most
 * recent first, or "No task to complete." The File Tracking board left (the
 * File Tracking page has it) and the hardcopy reference box moved to
 * Application Requests. Vendors land on their Requests list. Never shows
 * permission or role names; the routes authorise again.
 */
export default function Dashboard({ summary, queue }: Props) {
    const { can } = usePage<SharedData>().props;
    // DASH-1: the one crumb is the page itself, from the named route.
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'To Do', href: route('dashboard') }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="To Do" />
            <div className="flex w-full min-w-0 flex-1 flex-col gap-4 px-4 py-4 lg:px-6">
                <PageHeader className={RISE[0]} title="To Do" titleClassName="capitalize" subtitle={summary ?? undefined} />

                {/* Kyle, 2026-11-26: a reviewer or Legal officer lands here after recording their part. */}
                <FlashMessages />

                {queue && (
                    <div className={RISE[1]}>
                        <WorkQueue queue={queue} casesHref={can?.cases ? route('cases.index') : null} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
