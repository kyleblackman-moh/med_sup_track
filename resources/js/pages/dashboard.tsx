import { DraftsCard, type DashboardDrafts } from '@/components/dashboard/drafts-card';
import { RISE } from '@/components/dashboard/motion';
import { ReferenceLookup } from '@/components/dashboard/reference-lookup';
import { WorkQueue, type DashboardQueue, type WorkQueueData } from '@/components/dashboard/work-queue';
import { PageHeader } from '@/components/page-header';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, FileText, FolderOpen, type LucideIcon } from 'lucide-react';

interface Destination {
    title: string;
    description: string;
    href: string;
    icon: LucideIcon;
}

interface Props {
    /** Null for users with no queue permissions (vendors, auditors, ...). */
    workQueue: WorkQueueData | null;
    /** The greeting and one subtitle line, in local time (server). */
    header: { title: string; subtitle: string };
    /** Case id => request type name for the queue's rows (sent by the server; DASH-1: the rows carry their own type_label). */
    requestTypes: Record<string, string>;
    /** DASH-1: the queue card after the server applied the filter and the search; null without a queue. */
    queue: DashboardQueue | null;
    /** The user's own drafts (vendors); null for staff. Charts and tracking live on Analytics. */
    drafts: DashboardDrafts | null;
    /** HARDCOPY-1: show the reference lookup (hardcopy.confirm holders); the lookup authorises itself. */
    hardcopyLookup?: boolean;
}

const PRIMARY_ACTION = 'btn btn-primary btn-sm capitalize transition-transform active:scale-[0.97]';

/**
 * Landing page, one for every role (Flowbite look on daisyUI): the greeting,
 * then one card. Staff get the work queue; vendors get only Your Drafts (no
 * stats, charts or internal stage names); anyone else gets the places they can
 * go, driven by the shared `can` flags. Charts and tracking live on Analytics.
 * Never shows permission or role names; the routes authorise again.
 */
export default function Dashboard({ workQueue, header, queue, drafts, hardcopyLookup = false }: Props) {
    const { can } = usePage<SharedData>().props;
    // DASH-1: the one crumb is the page itself, from the named route.
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: route('dashboard') }];

    const destinations: Destination[] = [
        ...(can?.requests
            ? [
                  {
                      title: 'My Requests',
                      description: 'Start, continue and track your non-objection requests.',
                      href: route('requests.index'),
                      icon: FileText,
                  },
              ]
            : []),
        ...(can?.cases
            ? [{ title: 'File Tracking', description: 'Find any case and see where it is.', href: route('cases.index'), icon: FolderOpen }]
            : []),
    ];
    // The header's one primary action: the case list for staff, else the user's first destination. Vendors use the drafts card's New Request.
    const primary = drafts === null ? (destinations.at(-1) ?? null) : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex w-full min-w-0 flex-1 flex-col gap-4 px-4 py-4 lg:px-6">
                <PageHeader
                    className={RISE[0]}
                    titleClassName="capitalize"
                    title={header.title}
                    subtitle={header.subtitle}
                    actions={
                        primary && (
                            <Link href={primary.href} className={PRIMARY_ACTION}>
                                <primary.icon className="size-4" aria-hidden="true" />
                                {primary.title}
                            </Link>
                        )
                    }
                />

                {hardcopyLookup && (
                    <div className={RISE[1]}>
                        <ReferenceLookup />
                    </div>
                )}

                <div className={RISE[2]}>
                    {queue && drafts === null && <WorkQueue queue={queue} casesHref={can?.cases ? route('cases.index') : null} />}

                    {drafts && <DraftsCard drafts={drafts} canCreate={can?.requests === true} />}

                    {/* For users without a queue: the places they can go, as cards; else the empty state. */}
                    {!workQueue &&
                        drafts === null &&
                        (destinations.length === 0 ? (
                            <div className="card bg-base-100 border-base-300 border">
                                <EmptyState message="There is nothing for you to work on here yet." />
                            </div>
                        ) : (
                            <nav aria-label="Where to go">
                                <ul className="grid gap-4 md:grid-cols-2">
                                    {destinations.map((d) => (
                                        <li key={d.title} className="h-full">
                                            <Link
                                                href={d.href}
                                                className="card card-sm bg-base-100 border-base-300 h-full border transition-shadow duration-200 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.99]"
                                            >
                                                <span className="card-body flex-row items-center gap-3">
                                                    <span
                                                        className="bg-primary/10 text-primary rounded-field flex size-9 shrink-0 items-center justify-center"
                                                        aria-hidden="true"
                                                    >
                                                        <d.icon className="size-5" />
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="card-title text-base capitalize">{d.title}</span>
                                                        <span className="text-base-content/70 block text-sm">{d.description}</span>
                                                    </span>
                                                    <ChevronRight className="text-base-content/50 size-4 shrink-0" aria-hidden="true" />
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </nav>
                        ))}
                </div>
            </div>
        </AppLayout>
    );
}
