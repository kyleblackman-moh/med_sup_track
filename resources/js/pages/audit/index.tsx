import { AuditDetailSheet } from '@/components/audit/audit-detail-sheet';
import { AuditFiltersForm, hasActiveAuditFilters } from '@/components/audit/audit-filters';
import { AuditTable } from '@/components/audit/audit-table';
import { type AuditFilters, type AuditOptions, type AuditRouteBase, type AuditRow, EMPTY_FILTERS } from '@/components/audit/types';
import { PageContent, PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Lock } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Props {
    rows: AuditRow[];
    next_page_url: string | null;
    prev_page_url: string | null;
    filters: AuditFilters;
    options: AuditOptions;
    /** Which door the page came through: the IT Admin console or the CMO/Legal page. */
    route_base: AuditRouteBase;
}

/**
 * IT-ADMIN: the audit log viewer (/admin/audit in the console, /audit for
 * the CMO and Legal). Read-only. Filters, the cursor and every link come
 * from the server and live in the URL, so a filtered result can be shared
 * and opened again. Newest first; a row opens its redacted detail in a sheet.
 */
export default function AuditIndex({ rows, next_page_url: nextUrl, prev_page_url: prevUrl, filters, options, route_base: routeBase }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState<AuditRow | null>(null);

    const indexUrl = route(`${routeBase}.index`);
    const breadcrumbs: BreadcrumbItem[] =
        routeBase === 'admin.audit'
            ? [
                  { title: 'IT Admin', href: route('admin.dashboard') },
                  { title: 'Audit Log', href: indexUrl },
              ]
            : [{ title: 'Audit Log', href: indexUrl }];

    // Skeleton rows only while this list reloads (filters or paging).
    useEffect(() => {
        const listPath = new URL(indexUrl, window.location.origin).pathname;
        const offStart = router.on('start', (e) => {
            if (e.detail.visit.url.pathname === listPath) setLoading(true);
        });
        const offFinish = router.on('finish', () => setLoading(false));
        return () => {
            offStart();
            offFinish();
        };
    }, [indexUrl]);

    // Empty values stay out of the URL; a new filter set starts from the newest event.
    const apply = useCallback(
        (next: AuditFilters) => {
            const query = Object.fromEntries(Object.entries(next).filter(([, value]) => value !== ''));
            router.get(indexUrl, query, { preserveState: true, preserveScroll: true });
        },
        [indexUrl],
    );

    const filtersActive = hasActiveAuditFilters(filters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Log" />

            <PageContent>
                <PageHeader
                    title="Audit Log"
                    subtitle={
                        <span className="inline-flex items-center gap-1.5">
                            <Lock className="size-3.5" aria-hidden="true" />
                            Read-only record of who did what, and when. Times are Port of Spain.
                        </span>
                    }
                />

                <AuditFiltersForm filters={filters} options={options} errors={errors} onApply={apply} />

                <AuditTable
                    rows={rows}
                    loading={loading}
                    filtersActive={filtersActive}
                    onOpen={setOpen}
                    onClearFilters={() => apply(EMPTY_FILTERS)}
                />

                {(prevUrl || nextUrl) && (
                    <nav aria-label="Audit log pages" className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-base-content/70 text-helper">Newest first, {rows.length} on this page.</p>
                        <div className="join">
                            {prevUrl ? (
                                <Link href={prevUrl} preserveScroll className="join-item btn btn-sm capitalize" rel="prev">
                                    <ChevronLeft className="size-4" aria-hidden="true" />
                                    Newer
                                </Link>
                            ) : (
                                <span className="join-item btn btn-sm btn-disabled capitalize" aria-disabled="true">
                                    <ChevronLeft className="size-4" aria-hidden="true" />
                                    Newer
                                </span>
                            )}
                            {nextUrl ? (
                                <Link href={nextUrl} preserveScroll className="join-item btn btn-sm capitalize" rel="next">
                                    Older
                                    <ChevronRight className="size-4" aria-hidden="true" />
                                </Link>
                            ) : (
                                <span className="join-item btn btn-sm btn-disabled capitalize" aria-disabled="true">
                                    Older
                                    <ChevronRight className="size-4" aria-hidden="true" />
                                </span>
                            )}
                        </div>
                    </nav>
                )}
            </PageContent>

            <AuditDetailSheet routeBase={routeBase} row={open} onClose={() => setOpen(null)} />
        </AppLayout>
    );
}
