import { FileTrackingBoard } from '@/components/dashboard/file-tracking-board';
import { RISE } from '@/components/dashboard/motion';
import { ReferenceLookup } from '@/components/dashboard/reference-lookup';
import { WorkQueue, type DashboardQueue, type WorkQueueData } from '@/components/dashboard/work-queue';
import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type TrackingBoard } from '@/types/tracking';
import { Head, Link, usePage } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';

interface Props {
    /** Every staff user's queue (empty without queue permissions); null only for a vendor. */
    workQueue: WorkQueueData | null;
    /** "3 items waiting on you" (server); null without a queue. The greeting lives in the top bar. */
    summary: string | null;
    /** Case id => request type name for the queue's rows (sent by the server; DASH-1: the rows carry their own type_label). */
    requestTypes: Record<string, string>;
    /** DASH-1: the queue card after the server applied the filter and the search; null without a queue. */
    queue: DashboardQueue | null;
    /** Kyle, 2026-11-24: the File Tracking board (File Tracking holders); null otherwise. */
    tracking: TrackingBoard | null;
    /** HARDCOPY-1 (restored 2026-11-25): the reference box, for hardcopy.confirm holders; the lookup authorises itself. */
    hardcopyLookup?: boolean;
}

const PRIMARY_ACTION = 'btn btn-primary btn-sm capitalize transition-transform active:scale-[0.97]';

/**
 * Landing page for staff (vendors land on their Requests list; the greeting
 * and clock sit in the top bar). Backlog, 2026-11-25: "Needs My Action" for
 * every staff user, oldest first (empty when nothing is theirs); the hardcopy
 * reference box for those who record hardcopies; then the File Tracking board.
 * Never shows permission or role names; the routes authorise again.
 */
export default function Dashboard({ summary, queue, tracking, hardcopyLookup = false }: Props) {
    const { can } = usePage<SharedData>().props;
    // DASH-1: the one crumb is the page itself, from the named route.
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: route('dashboard') }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex w-full min-w-0 flex-1 flex-col gap-4 px-4 py-4 lg:px-6">
                <PageHeader
                    className={RISE[0]}
                    title="Dashboard"
                    titleClassName="capitalize"
                    subtitle={summary ?? undefined}
                    actions={
                        can?.cases && (
                            <Link href={route('cases.index')} className={PRIMARY_ACTION}>
                                <FolderOpen className="size-4" aria-hidden="true" />
                                Cases
                            </Link>
                        )
                    }
                />

                {hardcopyLookup && (
                    <div className={RISE[1]}>
                        <ReferenceLookup />
                    </div>
                )}

                {queue && (
                    <div className={RISE[1]}>
                        <WorkQueue queue={queue} casesHref={can?.cases ? route('cases.index') : null} />
                    </div>
                )}

                {tracking && (
                    <div className={RISE[2]}>
                        <FileTrackingBoard board={tracking} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
