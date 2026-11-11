import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

// Placeholder landing page: shows who is signed in and the permissions the
// server shared in auth.can. No role checks here; the server decides everything.
export default function Dashboard() {
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-6">
                    <h1 className="text-xl font-semibold">{auth.user.name}</h1>
                    <p className="text-muted-foreground text-sm">
                        {auth.user.email} · <span className="font-medium">{auth.user.user_type}</span>
                    </p>
                </div>
                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-6">
                    <h2 className="mb-3 font-medium">Your permissions</h2>
                    {auth.can.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No permissions assigned.</p>
                    ) : (
                        <ul className="grid gap-1 font-mono text-sm sm:grid-cols-2">
                            {auth.can.map((permission) => (
                                <li key={permission}>{permission}</li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
