import { type AdminUserDetail, type RoleOption, type UserAbilities } from '@/components/admin/users/types';
import { AccessActions, MfaPanel, RoleForm } from '@/components/admin/users/user-actions';
import { PageContent, PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import { StatusPill } from '@/components/ui/status-pill';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { type ReactNode } from 'react';

interface Props {
    user: AdminUserDetail;
    role_options: RoleOption[];
    abilities: UserAbilities;
    reason_max: number;
}

/** IT-ADMIN: one user's details and the actions the server allows. Every value is server-made (UserView::detail). */
export default function AdminUserShow({ user, role_options, abilities, reason_max }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Users', href: '/admin/users' },
        { title: user.name, href: route('admin.users.show', user.id) },
    ];

    const details: [string, ReactNode][] = [
        ['Email', user.email],
        ['Type', user.type],
        ['Organisation', user.organisation],
        ['Role', user.role],
        ['MFA', <StatusPill key="mfa" label={user.mfa_label} tone={user.mfa_tone} />],
        ['Last Sign-In', user.last_sign_in],
        ['Created On', user.created_on],
        ...(user.disabled_on ? ([['Disabled On', user.disabled_on]] as [string, ReactNode][]) : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={user.name} />

            <PageContent>
                <PageHeader title={user.name} actions={<StatusPill label={user.status_label} tone={user.status_tone} />} />

                <FlashMessages />

                {user.is_locked && user.locked_until && (
                    <div role="status" className="alert alert-soft alert-warning text-sm">
                        <Lock className="size-4 shrink-0" aria-hidden="true" />
                        <span>Locked after repeated failed sign-ins until {user.locked_until}. The lock ends on its own.</span>
                    </div>
                )}

                <section aria-labelledby="user-details-heading" className="card bg-base-100 border-base-300 border">
                    <div className="card-body gap-4 p-4 md:p-6">
                        <h2 id="user-details-heading" className="card-title text-base capitalize">
                            Account Details
                        </h2>
                        <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                            {details.map(([label, value]) => (
                                <div key={label} className="min-w-0 space-y-1">
                                    <dt className="text-base-content/70 text-helper capitalize">{label}</dt>
                                    <dd className="text-sm break-words tabular-nums">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </section>

                <div className="grid gap-5 xl:grid-cols-2">
                    {abilities.change_role && <RoleForm key={user.role_value ?? ''} user={user} options={role_options} reasonMax={reason_max} />}
                    <AccessActions user={user} abilities={abilities} reasonMax={reason_max} />
                    <MfaPanel user={user} canReset={abilities.mfa_reset} reasonMax={reason_max} />
                </div>
            </PageContent>
        </AppLayout>
    );
}
