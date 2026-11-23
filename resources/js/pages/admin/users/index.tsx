import { NO_USER_FILTERS, type AdminUserFilters, type AdminUserOptions, type AdminUserPage } from '@/components/admin/users/types';
import { hasActiveUserFilters, UserFilters } from '@/components/admin/users/user-filters';
import { UserTable } from '@/components/admin/users/user-table';
import { PageContent, PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Props {
    users: AdminUserPage;
    filters: AdminUserFilters;
    options: AdminUserOptions;
    abilities: { create: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/admin/users' }];

/** IT-ADMIN: Admin > Users. Search, filters and paging are server-side and live in the URL. */
export default function AdminUsersIndex({ users, filters, options, abilities }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [loading, setLoading] = useState(false);

    // Skeleton rows only while this list reloads, not when leaving for a user.
    useEffect(() => {
        const listPath = new URL(route('admin.users.index'), window.location.origin).pathname;
        const offStart = router.on('start', (e) => {
            if (e.detail.visit.url.pathname === listPath) setLoading(true);
        });
        const offFinish = router.on('finish', () => setLoading(false));
        return () => {
            offStart();
            offFinish();
        };
    }, []);

    // Any change returns to page 1; empty values stay out of the URL.
    const visit = useCallback(
        (changes: Partial<AdminUserFilters>) => {
            const query = Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, value]) => value !== ''));
            router.get(route('admin.users.index'), query, { preserveState: true, preserveScroll: true, replace: true });
        },
        [filters],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <PageContent>
                <PageHeader
                    title="Users"
                    subtitle={
                        <span className="tabular-nums" aria-live="polite">
                            {users.total} {users.total === 1 ? 'user' : 'users'}
                        </span>
                    }
                    actions={
                        abilities.create && (
                            <Link href={route('admin.users.create')} className="btn btn-primary btn-sm capitalize">
                                <UserPlus className="size-4" aria-hidden="true" />
                                New User
                            </Link>
                        )
                    }
                />

                <FlashMessages />

                <UserFilters filters={filters} options={options} errors={errors} onChange={visit} />

                <UserTable
                    page={users}
                    loading={loading}
                    filtersActive={hasActiveUserFilters(filters)}
                    onClearFilters={() => visit(NO_USER_FILTERS)}
                />
            </PageContent>
        </AppLayout>
    );
}
