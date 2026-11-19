import { PageContent, PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import { StatusPill } from '@/components/ui/status-pill';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AdminUserDetail } from '@/types/admin';
import { Head } from '@inertiajs/react';

interface Props {
    user: AdminUserDetail;
}

/** ADMIN-1: one user's details. Every value is server-made (UserView::detail). */
export default function AdminUserShow({ user }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Users', href: '/admin/users' },
        { title: user.name, href: route('admin.users.show', user.id) },
    ];

    const details: [string, string][] = [
        ['Email', user.email],
        ['Organisation', user.organisation],
        ['Role', user.role],
        ['Last Sign-In', user.last_sign_in],
        ['Created On', user.created_on],
        ...(user.disabled_on ? ([['Disabled On', user.disabled_on]] as [string, string][]) : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={user.name} />

            <PageContent>
                <PageHeader title={user.name} actions={<StatusPill label={user.status_label} tone={user.status_tone} />} />

                <FlashMessages />

                <section aria-labelledby="user-details-heading" className="bg-card rounded-xl border p-5 shadow-xs md:p-6">
                    <h2 id="user-details-heading" className="mb-4 text-base font-semibold">
                        Account Details
                    </h2>
                    <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        {details.map(([label, value]) => (
                            <div key={label} className="min-w-0 space-y-1">
                                <dt className="text-muted-foreground text-[13px]">{label}</dt>
                                <dd className="text-sm break-words tabular-nums">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            </PageContent>
        </AppLayout>
    );
}
