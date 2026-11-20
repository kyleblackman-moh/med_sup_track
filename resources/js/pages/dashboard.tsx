import { KpiBand } from '@/components/dashboard/kpi-band';
import { RISE } from '@/components/dashboard/motion';
import { ActivityFeed, GateList } from '@/components/dashboard/side-panels';
import { StageRail } from '@/components/dashboard/stage-rail';
import { VendorRequests } from '@/components/dashboard/vendor-requests';
import { WorkQueue, type WorkQueueData } from '@/components/dashboard/work-queue';
import { NewRequestButton } from '@/components/requests/new-request-button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type DashboardWidgets } from '@/types/dashboard';
import { Head, Link, usePage } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const TIMEZONE = 'America/Port_of_Spain';

interface Props {
    /** Null for users with no queue permissions (vendors, auditors, ...). */
    workQueue: WorkQueueData | null;
    /** The role's widgets (App\Support\Dashboard), chosen by permission on the server. */
    widgets: DashboardWidgets;
}

/** "Good morning" etc. by the time in Trinidad and Tobago, not the browser's zone. */
function greeting(now: Date): string {
    const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: TIMEZONE }).format(now));
    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';
    return 'Good evening';
}

/**
 * One dashboard template for every role (spec 12.3; Blueprint operations
 * dashboard): header with one primary action, the KPI band, the main card
 * (the work queue, or a vendor's drafts and requests) at two thirds, and the
 * stage rail, gate tracker and activity on the right. The server decides every
 * widget and count; React never reads roles or status codes.
 */
export default function Dashboard({ workQueue, widgets }: Props) {
    const { auth, can } = usePage<SharedData>().props;
    const now = new Date();
    const firstName = auth.user.name.trim().split(/\s+/)[0] ?? auth.user.name;
    const today = now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: TIMEZONE });
    const vendor = widgets.kind === 'vendor';
    const waiting = workQueue?.items.length ?? 0;

    let subtitle = 'Choose where to start.';
    if (vendor) {
        subtitle = `${widgets.drafts.total} ${widgets.drafts.total === 1 ? 'draft' : 'drafts'}, ${widgets.requests.total} submitted ${widgets.requests.total === 1 ? 'request' : 'requests'}.`;
    } else if (workQueue) {
        subtitle = waiting > 0 ? `${waiting} ${waiting === 1 ? 'item is' : 'items are'} waiting on you.` : 'Nothing is waiting on you.';
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-4 px-4 py-4 lg:px-6">
                <header className={RISE[0]}>
                    <div className="min-w-0">
                        <p className="text-base-content/60 text-sm">{today}</p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight text-balance capitalize">
                            {greeting(now)}, {firstName}
                        </h1>
                        <p className="text-base-content/70 mt-1 text-sm">{subtitle}</p>
                    </div>
                    {vendor
                        ? can?.requests && <NewRequestButton />
                        : can?.cases && (
                              <Link
                                  href={route('cases.index')}
                                  className="btn btn-primary btn-sm capitalize transition-transform active:scale-[0.97]"
                              >
                                  <FolderOpen className="size-4" aria-hidden="true" />
                                  File tracking
                              </Link>
                          )}
                </header>

                <div className={RISE[1]}>
                    <KpiBand kpis={widgets.kpis} />
                </div>

                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] 2xl:grid-cols-[minmax(0,1fr)_26rem]">
                    <div className={RISE[2]}>
                        {widgets.kind === 'vendor' ? (
                            <VendorRequests widgets={widgets} />
                        ) : workQueue ? (
                            <WorkQueue queue={workQueue} />
                        ) : (
                            <p className="text-base-content/60 text-sm">There is nothing for you to work on here yet.</p>
                        )}
                    </div>
                    <div className={RISE[3]}>
                        <StageRail stages={widgets.stages} title={vendor ? 'My requests by stage' : 'Cases by stage'} />
                        {widgets.kind === 'staff' && widgets.gate && <GateList gate={widgets.gate} />}
                        {widgets.kind === 'staff' && <ActivityFeed rows={widgets.activity} />}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
