import { Skeleton } from '@/components/ui/skeleton';
import { StatusPill } from '@/components/ui/status-pill';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FilterX, Users } from 'lucide-react';
import { type AdminUserPage } from './types';

interface Props {
    page: AdminUserPage;
    loading: boolean;
    filtersActive: boolean;
    onClearFilters: () => void;
}

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'type', label: 'Type' },
    { key: 'org', label: 'Organisation' },
    { key: 'role', label: 'Role' },
    { key: 'status', label: 'Status' },
    { key: 'mfa', label: 'MFA' },
    { key: 'last', label: 'Last Sign-In' },
];

/**
 * IT-ADMIN: the Users list. Every value, label and tone is server-made
 * (UserView::row). The name link is stretched over the row (rowLink); no
 * onClick on rows. Below md the rows become stacked cards.
 */
export function UserTable({ page, loading, filtersActive, onClearFilters }: Props) {
    const rows = page.data;

    return (
        <section aria-label="Users" className="card bg-base-100 border-base-300 min-w-0 border">
            {!loading && rows.length === 0 ? (
                <div className="text-base-content/70 flex flex-col items-center gap-3 px-6 py-14 text-center text-sm">
                    <Users className="text-base-content/40 size-6" aria-hidden="true" />
                    <p>{filtersActive ? 'No users match these filters.' : 'No users yet.'}</p>
                    {filtersActive && (
                        <button type="button" className="btn btn-ghost btn-sm capitalize" onClick={onClearFilters}>
                            <FilterX className="size-4" aria-hidden="true" />
                            Clear Filters
                        </button>
                    )}
                </div>
            ) : (
                <>
                    <div className="hidden max-w-full overflow-x-auto md:block" aria-busy={loading}>
                        <table className="table-sm table">
                            <caption className="sr-only">Users. Each row links to the user&apos;s details.</caption>
                            <thead>
                                <tr>
                                    {columns.map((col) => (
                                        <th key={col.key} scope="col" className="whitespace-nowrap capitalize">
                                            {col.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {loading
                                    ? Array.from({ length: 5 }, (_, r) => (
                                          <tr key={r}>
                                              {columns.map((col) => (
                                                  <td key={col.key}>
                                                      <Skeleton className="h-4 w-full min-w-12" />
                                                  </td>
                                              ))}
                                          </tr>
                                      ))
                                    : rows.map((row) => (
                                          <tr key={row.id} className="hover:bg-base-200 focus-within:bg-base-200 relative">
                                              <td className="whitespace-nowrap">
                                                  <Link href={route('admin.users.show', row.id)} className={cn('font-semibold', rowLink)}>
                                                      {row.name}
                                                  </Link>
                                              </td>
                                              <td className="max-w-64 truncate" title={row.email}>
                                                  {row.email}
                                              </td>
                                              <td className="whitespace-nowrap">{row.type}</td>
                                              <td className="whitespace-nowrap">{row.organisation}</td>
                                              <td className="whitespace-nowrap">{row.role}</td>
                                              <td>
                                                  <StatusPill label={row.status_label} tone={row.status_tone} inColumn />
                                              </td>
                                              <td>
                                                  <StatusPill label={row.mfa_label} tone={row.mfa_tone} inColumn />
                                              </td>
                                              <td className="whitespace-nowrap tabular-nums">{row.last_sign_in}</td>
                                          </tr>
                                      ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="divide-base-300 divide-y md:hidden" aria-busy={loading}>
                        {loading
                            ? Array.from({ length: 5 }, (_, r) => (
                                  <li key={r} className="space-y-2 p-4">
                                      <Skeleton className="h-4 w-2/5" />
                                      <Skeleton className="h-4 w-4/5" />
                                  </li>
                              ))
                            : rows.map((row) => (
                                  <li key={row.id}>
                                      <Link
                                          href={route('admin.users.show', row.id)}
                                          className="hover:bg-base-200 focus-visible:bg-base-200 block space-y-1 p-4 outline-none focus-visible:ring-2 focus-visible:ring-inset"
                                      >
                                          <span className="flex items-center justify-between gap-2">
                                              <span className="truncate font-semibold">{row.name}</span>
                                              <StatusPill label={row.status_label} tone={row.status_tone} />
                                          </span>
                                          <span className="block truncate text-sm">{row.email}</span>
                                          <span className="text-base-content/70 text-helper flex flex-wrap items-center gap-x-2 gap-y-1">
                                              <span>
                                                  {row.type} · {row.role} · {row.organisation}
                                              </span>
                                              <span>MFA: {row.mfa_label}</span>
                                          </span>
                                      </Link>
                                  </li>
                              ))}
                    </ul>
                </>
            )}

            {page.total > 0 && (
                <nav aria-label="User list pages" className="border-base-300 flex items-center justify-between gap-3 border-t px-4 py-2.5 text-sm">
                    <span className="text-base-content/70 tabular-nums">
                        Showing {page.from}–{page.to} of {page.total}
                    </span>
                    <div className="join">
                        <PageLink href={page.prev_page_url} label="Previous" icon="prev" />
                        <PageLink href={page.next_page_url} label="Next" icon="next" />
                    </div>
                </nav>
            )}
        </section>
    );
}

function PageLink({ href, label, icon }: { href: string | null; label: string; icon: 'prev' | 'next' }) {
    const content = (
        <>
            {icon === 'prev' && <ChevronLeft className="size-4" aria-hidden="true" />}
            {label}
            {icon === 'next' && <ChevronRight className="size-4" aria-hidden="true" />}
        </>
    );

    return href ? (
        <Link href={href} preserveScroll preserveState className="join-item btn btn-sm capitalize">
            {content}
        </Link>
    ) : (
        <button type="button" className="join-item btn btn-sm capitalize" disabled>
            {content}
        </button>
    );
}
