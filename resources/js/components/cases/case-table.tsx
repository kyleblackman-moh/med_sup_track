import { StatusBadge } from '@/components/cases/status-badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { type CaseFilters, type CaseListPage, type StaffCaseListRow } from '@/types/cases';
import { Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronsUpDown, ChevronUp, SearchX } from 'lucide-react';
import { type MouseEvent } from 'react';

/** Short labels for the seeded procurement type names; any other name shows in full. */
const TYPE_SHORT: Record<string, string> = {
    'Ad hoc purchase (Pharm)': 'Ad hoc · Pharm',
    'Ad hoc purchase (Non-Pharm)': 'Ad hoc · Non-Pharm',
    'Purchase in excess of tender': 'Excess of tender',
};

type SortKey = CaseFilters['sort'];

interface Column {
    key: string;
    label: string;
    sort?: SortKey;
    className?: string;
}

interface Props {
    page: CaseListPage;
    sort: SortKey;
    dir: CaseFilters['dir'];
    onSort: (key: SortKey) => void;
    showOrganisation: boolean;
    loading: boolean;
    filtersActive: boolean;
    onClearFilters: () => void;
}

const typeLabel = (name: string | null) => (name ? (TYPE_SHORT[name] ?? name) : '—');

const formatDate = (iso: string | null) =>
    iso ? new Date(iso).toLocaleDateString('en-GB', { timeZone: 'America/Port_of_Spain', day: 'numeric', month: 'short', year: 'numeric' }) : '—';

/** Row clicks open the case, except clicks on controls inside the row or while selecting text. */
function openCase(e: MouseEvent<HTMLTableRowElement>, row: StaffCaseListRow) {
    if ((e.target as HTMLElement).closest('a, button, input, select, textarea, label')) return;
    if (window.getSelection()?.toString()) return;
    router.visit(route('cases.show', row.id));
}

export function CaseTable({ page, sort, dir, onSort, showOrganisation, loading, filtersActive, onClearFilters }: Props) {
    const columns: Column[] = [
        { key: 'ref', label: 'Reference', sort: 'reference_no' },
        { key: 'type', label: 'Type' },
        { key: 'item', label: 'Item', className: 'w-full' },
        ...(showOrganisation ? [{ key: 'org', label: 'Organisation', className: 'hidden lg:table-cell' }] : []),
        { key: 'status', label: 'Status' },
        { key: 'holder', label: 'Holder', className: 'hidden lg:table-cell' },
        { key: 'submitted', label: 'Submitted', sort: 'submitted_at' },
        { key: 'days', label: 'Days', sort: 'days', className: 'text-right' },
    ];

    const cell = 'h-12 border-b border-border px-3 py-0 whitespace-nowrap';
    const rows = page.data;

    return (
        <div className="bg-card border-border rounded-lg border shadow-sm">
            {!loading && rows.length === 0 ? (
                <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
                    <SearchX className="text-muted-foreground size-8" aria-hidden="true" />
                    <p className="text-sm font-medium">{filtersActive ? 'No cases match these filters' : 'No cases yet'}</p>
                    {filtersActive && (
                        <Button variant="outline" size="sm" onClick={onClearFilters}>
                            Clear filters
                        </Button>
                    )}
                </div>
            ) : (
                <>
                    {/* md and up: table. Below lg it scrolls sideways, which stops the header sticking. */}
                    <div className="hidden overflow-x-auto md:block lg:overflow-visible" aria-busy={loading}>
                        <table className="w-full border-separate border-spacing-0 text-sm">
                            <caption className="sr-only">Cases. Sortable columns have buttons in their headers.</caption>
                            <TableHeader className="[&_tr]:border-0">
                                <TableRow className="border-0 hover:bg-transparent">
                                    {columns.map((col, i) => {
                                        const active = col.sort !== undefined && sort === col.sort;
                                        const Icon = active ? (dir === 'asc' ? ChevronUp : ChevronDown) : ChevronsUpDown;
                                        return (
                                            <TableHead
                                                key={col.key}
                                                scope="col"
                                                aria-sort={active ? (dir === 'asc' ? 'ascending' : 'descending') : undefined}
                                                // Opaque card base under a muted/60 layer, so rows never show through the sticky header.
                                                className={cn(
                                                    'bg-card from-muted/60 to-muted/60 text-muted-foreground border-border sticky top-0 z-10 h-10 border-b bg-linear-to-r px-3 text-xs font-medium whitespace-nowrap',
                                                    i === 0 && 'rounded-tl-lg',
                                                    i === columns.length - 1 && 'rounded-tr-lg',
                                                    col.className,
                                                )}
                                            >
                                                {col.sort ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => onSort(col.sort!)}
                                                        className={cn(
                                                            'hover:text-foreground focus-visible:ring-ring -mx-1 inline-flex items-center gap-1 rounded-sm px-1 outline-none focus-visible:ring-2',
                                                            active && 'text-foreground',
                                                        )}
                                                    >
                                                        {col.label}
                                                        <Icon className="size-3.5" aria-hidden="true" />
                                                    </button>
                                                ) : (
                                                    col.label
                                                )}
                                            </TableHead>
                                        );
                                    })}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading
                                    ? Array.from({ length: 5 }, (_, r) => (
                                          <TableRow key={r} className="border-0 hover:bg-transparent">
                                              {columns.map((col) => (
                                                  <TableCell key={col.key} className={cn(cell, col.className)}>
                                                      <Skeleton className="h-4 w-full min-w-12" />
                                                  </TableCell>
                                              ))}
                                          </TableRow>
                                      ))
                                    : rows.map((row) => (
                                          <TableRow
                                              key={row.id}
                                              onClick={(e) => openCase(e, row)}
                                              className="hover:bg-muted/60 focus-within:bg-muted/60 cursor-pointer border-0"
                                          >
                                              <TableCell className={cell}>
                                                  <Link
                                                      href={route('cases.show', row.id)}
                                                      className="focus-visible:ring-ring rounded-sm font-mono text-[13px] font-medium outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                                                  >
                                                      {row.reference_no ?? '—'}
                                                  </Link>
                                              </TableCell>
                                              <TableCell className={cell}>{typeLabel(row.procurement_type)}</TableCell>
                                              <TableCell className={cn(cell, 'max-w-0 truncate')} title={row.item_description ?? undefined}>
                                                  {row.item_description ?? '—'}
                                              </TableCell>
                                              {showOrganisation && (
                                                  <TableCell className={cn(cell, 'hidden lg:table-cell')}>{row.organisation ?? '—'}</TableCell>
                                              )}
                                              <TableCell className={cell}>
                                                  <StatusBadge label={row.status_label} tone={row.status_tone} hasOpenQuery={row.has_open_query} />
                                              </TableCell>
                                              <TableCell className={cn(cell, 'hidden lg:table-cell')}>{row.holder_label ?? '—'}</TableCell>
                                              <TableCell className={cn(cell, 'tabular-nums')}>{formatDate(row.submitted_at)}</TableCell>
                                              <TableCell className={cn(cell, 'text-right tabular-nums')}>{row.days_since_submitted ?? '—'}</TableCell>
                                          </TableRow>
                                      ))}
                            </TableBody>
                        </table>
                    </div>

                    {/* Below md: stacked cards. */}
                    <ul className="divide-border divide-y md:hidden" aria-busy={loading}>
                        {loading
                            ? Array.from({ length: 5 }, (_, r) => (
                                  <li key={r} className="space-y-2 p-4">
                                      <Skeleton className="h-4 w-2/5" />
                                      <Skeleton className="h-4 w-4/5" />
                                      <Skeleton className="h-3 w-1/3" />
                                  </li>
                              ))
                            : rows.map((row) => (
                                  <li key={row.id}>
                                      <Link
                                          href={route('cases.show', row.id)}
                                          className="hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:ring-ring block space-y-1 p-4 outline-none focus-visible:ring-2 focus-visible:ring-inset"
                                      >
                                          <span className="flex items-center justify-between gap-2">
                                              <span className="font-mono text-[13px] font-medium">{row.reference_no ?? '—'}</span>
                                              <StatusBadge label={row.status_label} tone={row.status_tone} hasOpenQuery={row.has_open_query} />
                                          </span>
                                          <span className="block truncate text-sm">{row.item_description ?? '—'}</span>
                                          <span className="text-muted-foreground block text-xs">
                                              {row.holder_label ?? '—'} ·{' '}
                                              <span className="tabular-nums">
                                                  {row.days_since_submitted === null
                                                      ? '—'
                                                      : `${row.days_since_submitted} ${row.days_since_submitted === 1 ? 'day' : 'days'}`}
                                              </span>
                                          </span>
                                      </Link>
                                  </li>
                              ))}
                    </ul>
                </>
            )}

            {page.total > 0 && (
                <nav aria-label="Case list pages" className="border-border flex items-center justify-between gap-3 border-t px-3 py-2.5 text-sm">
                    <span className="text-muted-foreground tabular-nums">
                        Showing {page.from}–{page.to} of {page.total}
                    </span>
                    <span className="flex gap-2">
                        <PageLink href={page.prev_page_url} label="Previous" />
                        <PageLink href={page.next_page_url} label="Next" />
                    </span>
                </nav>
            )}
        </div>
    );
}

function PageLink({ href, label }: { href: string | null; label: string }) {
    return href ? (
        <Button asChild variant="outline" size="sm">
            <Link href={href} preserveScroll preserveState>
                {label}
            </Link>
        </Button>
    ) : (
        <Button variant="outline" size="sm" disabled>
            {label}
        </Button>
    );
}
