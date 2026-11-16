import { WorkQueue, type WorkQueueData } from '@/components/dashboard/work-queue';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, FileText, FolderOpen, type LucideIcon } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const TIMEZONE = 'America/Port_of_Spain';

interface Destination {
    title: string;
    description: string;
    href: string;
    icon: LucideIcon;
}

interface Props {
    /** Null for users with no queue permissions (vendors, auditors, ...). */
    workQueue: WorkQueueData | null;
}

/** "Good morning" etc. by the time in Trinidad and Tobago, not the browser's zone. */
function greeting(now: Date): string {
    const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: TIMEZONE }).format(now));
    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';
    return 'Good evening';
}

/**
 * Landing page: a greeting, the work queue (when the server sends one) and
 * links driven by the shared `can` flags. Never shows permission or role
 * names; the routes authorise again.
 */
export default function Dashboard({ workQueue }: Props) {
    const { auth, can } = usePage<SharedData>().props;
    const now = new Date();
    const firstName = auth.user.name.trim().split(/\s+/)[0] ?? auth.user.name;
    const today = now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: TIMEZONE });
    const waiting = workQueue?.items.length ?? 0;

    const destinations: Destination[] = [
        ...(can?.requests
            ? [
                  {
                      title: 'My requests',
                      description: 'Start, continue and track your non-objection requests.',
                      href: route('requests.index'),
                      icon: FileText,
                  },
              ]
            : []),
        ...(can?.cases
            ? [{ title: 'File tracking', description: 'Find any case and see where it is.', href: route('cases.index'), icon: FolderOpen }]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <header>
                    <p className="text-muted-foreground text-sm">{today}</p>
                    <h1 className="mt-1 text-xl font-semibold">
                        {greeting(now)}, {firstName}
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {workQueue
                            ? waiting === 0
                                ? 'Nothing is waiting on you right now.'
                                : `You have ${waiting} ${waiting === 1 ? 'item' : 'items'} waiting on you.`
                            : 'Choose where to start.'}
                    </p>
                </header>

                {workQueue && <WorkQueue queue={workQueue} />}

                {!workQueue &&
                    (destinations.length === 0 ? (
                        <p className="text-muted-foreground text-sm">There is nothing for you to work on here yet.</p>
                    ) : (
                        <ul className="grid gap-4 sm:grid-cols-2">
                            {destinations.map((d) => (
                                <li key={d.title}>
                                    <Link
                                        href={d.href}
                                        className="bg-card hover:bg-accent focus-visible:ring-ring flex items-center gap-4 rounded-lg border p-6 shadow-sm transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                    >
                                        <d.icon className="text-primary size-5 shrink-0" aria-hidden />
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
            </div>
        </AppLayout>
    );
}
