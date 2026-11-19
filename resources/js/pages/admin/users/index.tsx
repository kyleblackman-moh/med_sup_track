import { hasActiveUserFilters, UserFilters } from '@/components/admin/user-filters';
import { UserTable } from '@/components/admin/user-table';
import { PageContent, PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AdminUserFilters, type AdminUserOptions, type AdminUserPage } from '@/types/admin';
import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

interface Props {
    users: AdminUserPage;
    filters: AdminUserFilters;
    options: AdminUserOptions;
    abilities: { create: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/admin/users' }];

/** ADMIN-1: Admin > Users. Search, filters and paging are server-side and live in the URL. */
export default function AdminUsersIndex({ users, filters, options }: Props) {
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
                />

                <FlashMessages />

                <UserFilters filters={filters} options={options} errors={errors} onChange={visit} />

                <UserTable
                    page={users}
                    loading={loading}
                    filtersActive={hasActiveUserFilters(filters)}
                    onClearFilters={() => visit({ q: '', org: '', role: '', status: '' })}
                />
            </PageContent>
        </AppLayout>
    );
}
