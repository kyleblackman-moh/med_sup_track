import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusPill } from '@/components/ui/status-pill';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type AdminUserPage } from '@/types/admin';
import { Link } from '@inertiajs/react';

interface Props {
    page: AdminUserPage;
    loading: boolean;
    filtersActive: boolean;
    onClearFilters: () => void;
}

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email', className: 'w-full' },
    { key: 'org', label: 'Organisation', className: 'hidden lg:table-cell' },
    { key: 'role', label: 'Role' },
    { key: 'status', label: 'Status' },
    { key: 'last', label: 'Last Sign-In', className: 'hidden lg:table-cell' },
];

/**
 * ADMIN-1: the Users list. Every value is server-made (UserView::row). The
 * name link is stretched over the row (rowLink); no onClick on rows.
 */
export function UserTable({ page, loading, filtersActive, onClearFilters }: Props) {
    const cell = 'h-12 border-b border-divider px-3 py-0 whitespace-nowrap';
    const rows = page.data;

    return (
        <div className="bg-card rounded-xl border shadow-xs">
            {!loading && rows.length === 0 ? (
                <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
                    <p className="text-muted-foreground text-sm">{filtersActive ? 'No users match these filters' : 'No users yet'}</p>
                    {filtersActive && (
                        <Button variant="outline" size="sm" onClick={onClearFilters}>
                            Clear Filters
                        </Button>
                    )}
                </div>
            ) : (
                <>
                    {/* md and up: table. Below lg it scrolls sideways inside the card. */}
                    <div className="hidden overflow-x-auto md:block" aria-busy={loading}>
                        <table className="w-full border-separate border-spacing-0 text-sm">
                            <caption className="sr-only">Users. Each row links to the user&apos;s details.</caption>
                            <TableHeader className="[&_tr]:border-0">
                                <TableRow className="border-0 hover:bg-transparent">
                                    {columns.map((col, i) => (
                                        <TableHead
                                            key={col.key}
                                            scope="col"
                                            className={cn(
                                                'bg-subtle text-muted-foreground border-divider h-10 border-b px-3 text-xs font-medium whitespace-nowrap',
                                                i === 0 && 'rounded-tl-xl',
                                                i === columns.length - 1 && 'rounded-tr-xl',
                                                col.className,
                                            )}
                                        >
                                            {col.label}
                                        </TableHead>
                                    ))}
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
                                          <TableRow key={row.id} className="hover:bg-muted/60 focus-within:bg-muted/60 relative border-0">
                                              <TableCell className={cell}>
                                                  <Link href={route('admin.users.show', row.id)} className={cn('font-semibold', rowLink)}>
                                                      {row.name}
                                                  </Link>
                                              </TableCell>
                                              <TableCell className={cn(cell, 'max-w-0 truncate')} title={row.email}>
                                                  {row.email}
                                              </TableCell>
                                              <TableCell className={cn(cell, 'hidden lg:table-cell')}>{row.organisation}</TableCell>
                                              <TableCell className={cell}>{row.role}</TableCell>
                                              <TableCell className={cell}>
                                                  <StatusPill label={row.status_label} tone={row.status_tone} inColumn />
                                              </TableCell>
                                              <TableCell className={cn(cell, 'hidden tabular-nums lg:table-cell')}>{row.last_sign_in}</TableCell>
                                          </TableRow>
                                      ))}
                            </TableBody>
                        </table>
                    </div>

                    {/* Below md: stacked cards. */}
                    <ul className="divide-divider divide-y md:hidden" aria-busy={loading}>
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
                                          className="hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:ring-ring block space-y-1 p-4 outline-none focus-visible:ring-2 focus-visible:ring-inset"
                                      >
                                          <span className="flex items-center justify-between gap-2">
                                              <span className="truncate font-semibold">{row.name}</span>
                                              <StatusPill label={row.status_label} tone={row.status_tone} inColumn />
                                          </span>
                                          <span className="block truncate text-sm">{row.email}</span>
                                          <span className="text-muted-foreground block text-xs">
                                              {row.role} · {row.organisation}
                                          </span>
                                      </Link>
                                  </li>
                              ))}
                    </ul>
                </>
            )}

            {page.total > 0 && (
                <nav aria-label="User list pages" className="flex items-center justify-between gap-3 border-t px-3 py-2.5 text-sm">
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
