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

interface Destination {
    title: string;
    description: string;
    href: string;
    icon: LucideIcon;
}

/**
 * Landing page: a greeting and links driven by the server's shared `can`
 * flags. Never shows permission or role names; the routes authorise again.
 */
export default function Dashboard() {
    const { auth, can } = usePage<SharedData>().props;

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
            ? [{ title: 'Cases', description: 'Find and work on the cases available to you.', href: route('cases.index'), icon: FolderOpen }]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-lg font-semibold">Welcome, {auth.user.name}</h1>
                    <p className="text-muted-foreground text-sm">Choose where to start.</p>
                </div>

                {destinations.length === 0 ? (
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
                )}
            </div>
        </AppLayout>
    );
}
