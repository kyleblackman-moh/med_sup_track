import { CaseFilters, hasActiveFilters } from '@/components/cases/case-filters';
import { CaseTable } from '@/components/cases/case-table';
import { ReferenceLookup } from '@/components/dashboard/reference-lookup';
import { PageContent, PageHeader } from '@/components/page-header';
import { NewRequestButton } from '@/components/requests/new-request-button';
import { FilterChips, type FilterChip } from '@/components/ui/filter-chips';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type CaseListCan, type CaseListOptions, type CaseListPage, type CaseFilters as Filters } from '@/types/cases';
import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

interface Props {
    /** Backlog, 2026-11-25: the status chips (All, In Review, Awaiting CAB Funding, With PS, Awaiting Letter), built by the server. */
    chips: FilterChip[];
    cases: CaseListPage;
    filters: Filters;
    options: CaseListOptions;
    abilities: CaseListCan;
    /** Kyle, 2026-11-26 (moved from the dashboard): the hardcopy reference box, for hardcopy.confirm holders; the lookup authorises itself. */
    hardcopyLookup?: boolean;
}

// Kyle, 2026-11-26: the staff case list is "Application Requests" (was Cases).
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Application Requests', href: '/cases' }];

/** Staff case register (slice 9a). Read-only; filters, sorting and paging are server-side and live in the URL. */
export default function CasesIndex({ chips, cases, filters, options, abilities, hardcopyLookup = false }: Props) {
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

    const clearFilters = () => visit({ status: '', type: '', org: '', ref: '', q: '', awaiting: '' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Application Requests" />

            {/* Full width beside the drawer: px-4 py-4, lg:px-6 (PageContent's md:p-6 reset to p-4 first). */}
            <PageContent className="space-y-3 px-4 py-4 md:p-4 lg:px-6">
                <PageHeader
                    title="Application Requests"
                    titleClassName="capitalize"
                    subtitle={
                        <span className="tabular-nums" aria-live="polite">
                            {cases.total} {cases.total === 1 ? 'request' : 'requests'}
                        </span>
                    }
                    actions={abilities.create && <NewRequestButton />}
                />

                {hardcopyLookup && <ReferenceLookup />}

                <FilterChips label="Filter application requests by status" chips={chips} />

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
            </PageContent>
        </AppLayout>
    );
}
