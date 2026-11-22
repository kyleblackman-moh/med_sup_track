import { JoinPagination } from '@/components/ui/join-pagination';
import { StatusPill } from '@/components/ui/status-pill';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type CaseFilters, type CaseListPage } from '@/types/cases';
import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronsUpDown, ChevronUp, FilterX, SearchX } from 'lucide-react';

/** Short labels for the seeded procurement type names; any other name shows in full. */
const TYPE_SHORT: Record<string, string> = {
    'Ad Hoc Purchase (Pharm)': 'Ad Hoc · Pharm',
    'Ad Hoc Purchase (Non-Pharm)': 'Ad Hoc · Non-Pharm',
    'Purchase in Excess of Tender': 'Excess of Tender',
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

export function CaseTable({ page, sort, dir, onSort, showOrganisation, loading, filtersActive, onClearFilters }: Props) {
    const columns: Column[] = [
        { key: 'ref', label: 'Reference', sort: 'reference_no' },
        { key: 'type', label: 'Type' },
        { key: 'item', label: 'Item', className: 'w-full' },
        ...(showOrganisation ? [{ key: 'org', label: 'Organisation', className: 'max-lg:hidden' }] : []),
        { key: 'status', label: 'Status' },
        { key: 'holder', label: 'Holder', className: 'max-lg:hidden' },
        { key: 'submitted', label: 'Submitted', sort: 'submitted_at' },
        { key: 'days', label: 'Days', sort: 'days', className: 'text-right' },
    ];

    const rows = page.data;

    return (
        <div className="card card-border bg-base-100 min-w-0">
            {!loading && rows.length === 0 ? (
                <div className="flex flex-col items-center gap-2 px-6 py-16 text-center">
                    <SearchX className="size-8 opacity-60" aria-hidden="true" />
                    <p className="text-sm font-medium">{filtersActive ? 'No cases match these filters' : 'No cases yet'}</p>
                    <p className="text-base-content/70 max-w-sm text-sm">
                        {filtersActive
                            ? 'Try another reference or remove a filter to see more cases.'
                            : 'Requests appear here once a vendor submits them.'}
                    </p>
                    {filtersActive && (
                        <button type="button" className="btn btn-sm mt-2 capitalize" onClick={onClearFilters}>
                            <FilterX className="size-4" aria-hidden="true" />
                            Clear Filters
                        </button>
                    )}
                </div>
            ) : (
                <>
                    {/* md and up: table. Below lg it scrolls sideways inside its wrapper; from lg the header pins to the page. */}
                    <div className="hidden max-w-full min-w-0 overflow-x-auto md:block lg:overflow-visible" aria-busy={loading}>
                        <table className="table-sm table-pin-rows table">
                            <caption className="sr-only">Cases. Sortable columns have buttons in their headers.</caption>
                            <thead>
                                <tr className="capitalize">
                                    {columns.map((col) => {
                                        const active = col.sort !== undefined && sort === col.sort;
                                        const Icon = active ? (dir === 'asc' ? ChevronUp : ChevronDown) : ChevronsUpDown;
                                        return (
                                            <th
                                                key={col.key}
                                                scope="col"
                                                aria-sort={active ? (dir === 'asc' ? 'ascending' : 'descending') : undefined}
                                                className={cn('whitespace-nowrap', col.className)}
                                            >
                                                {col.sort ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => onSort(col.sort!)}
                                                        className={
                                                            active
                                                                ? 'btn btn-ghost btn-xs -mx-2 gap-1 font-medium capitalize'
                                                                : 'btn btn-ghost btn-xs text-base-content/70 -mx-2 gap-1 font-medium capitalize'
                                                        }
                                                    >
                                                        {col.label}
                                                        <Icon className="size-3.5" aria-hidden="true" />
                                                    </button>
                                                ) : (
                                                    col.label
                                                )}
                                            </th>
                                        );
                                    })}
                                </tr>
                            </thead>
                            <tbody
                                key={loading ? 'loading' : 'rows'}
                                className="motion-safe:transition-opacity motion-safe:duration-300 motion-safe:starting:opacity-0"
                            >
                                {loading
                                    ? Array.from({ length: 5 }, (_, r) => (
                                          <tr key={r}>
                                              {columns.map((col) => (
                                                  <td key={col.key} className={col.className}>
                                                      <div className="skeleton h-4 w-full min-w-12" />
                                                  </td>
                                              ))}
                                          </tr>
                                      ))
                                    : rows.map((row) => (
                                          <tr
                                              key={row.id}
                                              // The reference link covers the row (rowLink); no onClick on the row.
                                              className="hover:bg-base-200 focus-within:bg-base-200 relative transition-colors"
                                          >
                                              <td className="whitespace-nowrap">
                                                  <Link href={route('cases.show', row.id)} className={cn('font-mono font-medium', rowLink)}>
                                                      {row.reference_no ?? '—'}
                                                  </Link>
                                              </td>
                                              <td className="whitespace-nowrap">{typeLabel(row.procurement_type)}</td>
                                              <td className="max-w-0 truncate" title={row.item_description ?? undefined}>
                                                  {row.item_description ?? '—'}
                                              </td>
                                              {showOrganisation && <td className="whitespace-nowrap max-lg:hidden">{row.organisation ?? '—'}</td>}
                                              <td className="whitespace-nowrap">
                                                  <StatusPill
                                                      label={row.status_label}
                                                      tone={row.status_tone}
                                                      openQuery={row.has_open_query}
                                                      inColumn
                                                  />
                                              </td>
                                              <td className="whitespace-nowrap max-lg:hidden">{row.holder_label ?? '—'}</td>
                                              <td className="whitespace-nowrap tabular-nums">{formatDate(row.submitted_at)}</td>
                                              <td className="text-right whitespace-nowrap tabular-nums">{row.days_since_submitted ?? '—'}</td>
                                          </tr>
                                      ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Below md: stacked list rows; the reference link covers each row (rowLink). */}
                    <ul className="list md:hidden" aria-busy={loading}>
                        {loading
                            ? Array.from({ length: 5 }, (_, r) => (
                                  <li key={r} className="list-row">
                                      <div className="list-col-grow flex flex-col gap-2">
                                          <div className="skeleton h-4 w-2/5" />
                                          <div className="skeleton h-4 w-4/5" />
                                          <div className="skeleton h-3 w-1/3" />
                                      </div>
                                  </li>
                              ))
                            : rows.map((row) => (
                                  <li key={row.id} className="list-row hover:bg-base-200 focus-within:bg-base-200 relative transition-colors">
                                      <div className="list-col-grow min-w-0 space-y-1">
                                          <Link href={route('cases.show', row.id)} className={cn('block font-mono text-sm font-medium', rowLink)}>
                                              {row.reference_no ?? '—'}
                                          </Link>
                                          <span className="block truncate text-sm">{row.item_description ?? '—'}</span>
                                          <span className="text-base-content/70 block text-xs">
                                              {row.holder_label ?? '—'} ·{' '}
                                              <span className="tabular-nums">
                                                  {row.days_since_submitted === null
                                                      ? '—'
                                                      : `${row.days_since_submitted} ${row.days_since_submitted === 1 ? 'day' : 'days'}`}
                                              </span>
                                          </span>
                                      </div>
                                      <StatusPill label={row.status_label} tone={row.status_tone} openQuery={row.has_open_query} inColumn />
                                  </li>
                              ))}
                    </ul>
                </>
            )}

            {page.total > 0 && (
                <JoinPagination
                    label="Case list pages"
                    from={page.from}
                    to={page.to}
                    total={page.total}
                    prevUrl={page.prev_page_url}
                    nextUrl={page.next_page_url}
                />
            )}
        </div>
    );
}
