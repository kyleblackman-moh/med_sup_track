import { GateTrackerPanel, PipelinePanel, type GateTrackerData, type PipelineStage } from '@/components/dashboard/pipeline-panels';
import { WorkQueue, type DashboardQueue, type WorkQueueData } from '@/components/dashboard/work-queue';
import { PageContent, PageHeader } from '@/components/page-header';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { StatTile } from '@/components/ui/stat-tile';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, FileCheck, FileClock, FileText, FolderOpen, Inbox, type LucideIcon } from 'lucide-react';

interface Destination {
    title: string;
    description: string;
    href: string;
    icon: LucideIcon;
}

/** One stat tile, built and formatted by the server (DashboardView::stats). */
interface Stat {
    key: string;
    label: string;
    value: string;
    /** DASH-1: one short sentence-case line from the server, shown in the footer strip. */
    description: string;
    /** Only where the case list already has a matching filter. */
    href: string | null;
}

interface Props {
    /** Null for users with no queue permissions (vendors, auditors, ...). */
    workQueue: WorkQueueData | null;
    /** The greeting and one subtitle line, in local time (server). */
    header: { title: string; subtitle: string };
    /** Up to four tiles, or null when the user has none. */
    stats: Stat[] | null;
    /** Case id => request type name for the queue's rows (sent by the server; DASH-1: the rows carry their own type_label). */
    requestTypes: Record<string, string>;
    /** DASH-1: the queue card after the server applied the filter and the search; null without a queue. */
    queue: DashboardQueue | null;
    /** OVERNIGHT-2 (C1): a count per stage; null without the staff case list. */
    pipeline: PipelineStage[] | null;
    /** OVERNIGHT-2 (C2): In Review cases and their gate items; null without case.view_all. */
    gateTracker: GateTrackerData | null;
}

/** Display only: an icon per tile key. Unknown keys get no icon. */
const STAT_ICONS: Record<string, LucideIcon> = {
    needs_action: Inbox,
    open: FolderOpen,
    awaiting_hardcopy: FileClock,
    ready_for_file: FileCheck,
};

/**
 * Landing page, one for every role (design.md section 6): the page header,
 * the server's stat tiles, the work queue (when the server sends one) and,
 * for users without a queue, links driven by the shared `can` flags. Never
 * shows permission or role names; the routes authorise again.
 */
export default function Dashboard({ workQueue, header, stats, queue, pipeline, gateTracker }: Props) {
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <PageContent>
                {/* DASH-1: the shared page header (h1 20px/600, 13px muted subtitle); greeting and date line from the server. */}
                <PageHeader title={header.title} subtitle={header.subtitle} />

                {/* DASH-1: equal-height tiles, 4 columns at lg, 2 at md, 1 below. Values and descriptions come from the server. */}
                {stats && stats.length > 0 && (
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                        {stats.map((stat) => (
                            <StatTile
                                key={stat.key}
                                label={stat.label}
                                value={stat.value}
                                hint={stat.description}
                                icon={STAT_ICONS[stat.key]}
                                href={stat.href ?? undefined}
                                className="h-full"
                            />
                        ))}
                    </div>
                )}

                {pipeline && <PipelinePanel stages={pipeline} />}

                {gateTracker && <GateTrackerPanel tracker={gateTracker} />}

                {queue && <WorkQueue queue={queue} casesHref={can?.cases ? route('cases.index') : null} />}

                {/* DASH-1: for users without a queue, the places they can go, as L3 cards like the stat tiles
                    (30px tinted icon square, --shadow-card); the empty state is centred muted text. */}
                {!workQueue &&
                    (destinations.length === 0 ? (
                        <Panel as="div" padding="none">
                            <EmptyState message="There is nothing for you to work on here yet." />
                        </Panel>
                    ) : (
                        <nav aria-label="Where to go">
                            <ul className="grid gap-3 md:grid-cols-2">
                                {destinations.map((d) => (
                                    <li key={d.title} className="h-full">
                                        <Link
                                            href={d.href}
                                            className="bg-card shadow-card hover:border-input focus-visible:ring-ring flex h-full items-center gap-3 rounded-xl border p-4 transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-offset-2 motion-reduce:transition-none"
                                        >
                                            <span
                                                className="bg-accent text-accent-foreground flex size-[30px] shrink-0 items-center justify-center rounded-lg"
                                                aria-hidden="true"
                                            >
                                                <d.icon className="size-5" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="text-label block font-semibold">{d.title}</span>
                                                <span className="text-muted-foreground text-helper block">{d.description}</span>
                                            </span>
                                            <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </nav>
                    ))}
            </PageContent>
        </AppLayout>
    );
}
