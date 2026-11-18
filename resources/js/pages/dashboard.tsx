import { WorkQueue, type WorkQueueData } from '@/components/dashboard/work-queue';
import { PageContent, PageHeader } from '@/components/page-header';
import { StatTile } from '@/components/ui/stat-tile';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, FileCheck, FileClock, FileText, FolderOpen, Inbox, type LucideIcon } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

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
    hint: string | null;
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
    /** Case id => request type name for the queue's rows. */
    requestTypes: Record<string, string>;
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
export default function Dashboard({ workQueue, header, stats, requestTypes }: Props) {
    const { can } = usePage<SharedData>().props;

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
                <PageHeader title={header.title} subtitle={header.subtitle} />

                {stats && stats.length > 0 && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {stats.map((stat) => (
                            <StatTile
                                key={stat.key}
                                label={stat.label}
                                value={stat.value}
                                hint={stat.hint ?? undefined}
                                icon={STAT_ICONS[stat.key]}
                                href={stat.href ?? undefined}
                            />
                        ))}
                    </div>
                )}

                {workQueue && <WorkQueue queue={workQueue} requestTypes={requestTypes} casesHref={can?.cases ? route('cases.index') : null} />}

                {!workQueue &&
                    (destinations.length === 0 ? (
                        <p className="text-muted-foreground text-sm">There is nothing for you to work on here yet.</p>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {destinations.map((d) => (
                                <li key={d.title}>
                                    <Link
                                        href={d.href}
                                        className="bg-card hover:border-input focus-visible:ring-ring flex items-center gap-4 rounded-xl border p-5 shadow-xs transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                    >
                                        <span
                                            className="bg-accent text-accent-foreground flex size-8 shrink-0 items-center justify-center rounded-lg"
                                            aria-hidden
                                        >
                                            <d.icon className="size-4" />
                                        </span>
                                        <span className="flex-1">
                                            <span className="block font-semibold">{d.title}</span>
                                            <span className="text-muted-foreground block text-sm">{d.description}</span>
                                        </span>
                                        <ChevronRight className="text-muted-foreground size-4" aria-hidden />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ))}
            </PageContent>
        </AppLayout>
    );
}
