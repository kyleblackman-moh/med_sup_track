import { CaseFilters, hasActiveFilters } from '@/components/cases/case-filters';
import { CaseTable } from '@/components/cases/case-table';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type CaseListCan, type CaseListOptions, type CaseListPage, type CaseFilters as Filters } from '@/types/cases';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

interface Props {
    cases: CaseListPage;
    filters: Filters;
    options: CaseListOptions;
    abilities: CaseListCan;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cases', href: '/cases' }];

/** Staff case register (slice 9a). Read-only; filters, sorting and paging are server-side and live in the URL. */
export default function CasesIndex({ cases, filters, options, abilities }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [loading, setLoading] = useState(false);

    // Skeleton rows only while this list reloads, not when leaving for a case.
    useEffect(() => {
        const listPath = new URL(route('cases.index'), window.location.origin).pathname;
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
        (changes: Partial<Filters>) => {
            const query = Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, value]) => value !== ''));
            router.get(route('cases.index'), query, { preserveState: true, preserveScroll: true, replace: true });
        },
        [filters],
    );

    const sortBy = (key: Filters['sort']) => {
        const dir = filters.sort === key ? (filters.dir === 'asc' ? 'desc' : 'asc') : key === 'reference_no' ? 'asc' : 'desc';
        visit({ sort: key, dir });
    };

    const clearFilters = () => visit({ status: '', type: '', org: '', ref: '', awaiting: '' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cases" />

            <div className="bg-background text-foreground w-full p-6">
                <header className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-baseline gap-3">
                        <h1 className="text-xl font-semibold">Cases</h1>
                        <span className="text-muted-foreground text-sm tabular-nums" aria-live="polite">
                            {cases.total} {cases.total === 1 ? 'case' : 'cases'}
                        </span>
                    </div>
                    {abilities.create && (
                        <Button asChild>
                            <Link href={route('requests.store')} method="post" as="button">
                                New request
                            </Link>
                        </Button>
                    )}
                </header>

                <CaseFilters filters={filters} options={options} showOrganisation={abilities.see_organisation} errors={errors} onChange={visit} />

                <CaseTable
                    page={cases}
                    sort={filters.sort}
                    dir={filters.dir}
                    onSort={sortBy}
                    showOrganisation={abilities.see_organisation}
                    loading={loading}
                    filtersActive={hasActiveFilters(filters)}
                    onClearFilters={clearFilters}
                />
            </div>
        </AppLayout>
    );
}
