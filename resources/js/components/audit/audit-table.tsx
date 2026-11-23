import { OutcomeBadge } from '@/components/audit/outcome-badge';
import { type AuditRow } from '@/components/audit/types';
import { ChevronRight, FileSearch, Server, UserRound } from 'lucide-react';

interface Props {
    rows: AuditRow[];
    loading: boolean;
    filtersActive: boolean;
    onOpen: (row: AuditRow) => void;
    onClearFilters: () => void;
}

const SKELETON_ROWS = 8;

function ActorCell({ actor }: { actor: string }) {
    const service = actor === 'System' || actor === 'Anonymous';
    return (
        <span className="inline-flex min-w-0 items-center gap-1.5">
            {service ? (
                <Server className="size-3.5 shrink-0 opacity-60" aria-hidden="true" />
            ) : (
                <UserRound className="size-3.5 shrink-0 opacity-60" aria-hidden="true" />
            )}
            <span className="break-all">{actor}</span>
        </span>
    );
}

/**
 * IT-ADMIN: the ledger. Newest first, as the server sends it. Time leads,
 * then who, what, the case, the outcome; source IP and request ID trail.
 * Each row opens its detail (the redacted metadata) without leaving the list.
 */
export function AuditTable({ rows, loading, filtersActive, onOpen, onClearFilters }: Props) {
    if (!loading && rows.length === 0) {
        return (
            <div className="bg-base-100 border-base-300 rounded-box flex flex-col items-center gap-3 border px-6 py-12 text-center" role="status">
                <FileSearch className="size-6 opacity-60" aria-hidden="true" />
                <p className="text-base-content/70 text-sm">
                    {filtersActive ? 'No audit events match these filters.' : 'No audit events have been recorded yet.'}
                </p>
                {filtersActive && (
                    <button type="button" className="btn btn-sm capitalize" onClick={onClearFilters}>
                        Clear Filters
                    </button>
                )}
            </div>
        );
    }

    return (
        <div className="bg-base-100 border-base-300 rounded-box max-w-full min-w-0 overflow-x-auto border" aria-busy={loading || undefined}>
            <table className="table-sm table">
                <caption className="sr-only">Audit events, newest first. Times are Port of Spain.</caption>
                <thead>
                    <tr>
                        <th scope="col" className="capitalize">
                            Time
                        </th>
                        <th scope="col" className="capitalize">
                            Actor
                        </th>
                        <th scope="col" className="capitalize">
                            Action
                        </th>
                        <th scope="col" className="capitalize">
                            Case
                        </th>
                        <th scope="col" className="capitalize">
                            Outcome
                        </th>
                        <th scope="col" className="capitalize">
                            Source IP
                        </th>
                        <th scope="col">
                            <span className="sr-only">Details</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {loading
                        ? Array.from({ length: SKELETON_ROWS }, (_, i) => (
                              <tr key={i} aria-hidden="true">
                                  <td>
                                      <div className="skeleton h-4 w-36" />
                                  </td>
                                  <td>
                                      <div className="skeleton h-4 w-44" />
                                  </td>
                                  <td>
                                      <div className="skeleton h-4 w-32" />
                                  </td>
                                  <td>
                                      <div className="skeleton h-4 w-24" />
                                  </td>
                                  <td>
                                      <div className="skeleton h-5 w-16" />
                                  </td>
                                  <td>
                                      <div className="skeleton h-4 w-24" />
                                  </td>
                                  <td />
                              </tr>
                          ))
                        : rows.map((row) => (
                              <tr key={row.id} className="hover:bg-base-200 align-top">
                                  <td className="font-mono text-xs whitespace-nowrap tabular-nums">{row.occurred_at}</td>
                                  <td className="min-w-48">
                                      <ActorCell actor={row.actor} />
                                  </td>
                                  <td className="min-w-40">
                                      <span className="block font-mono text-xs">{row.action}</span>
                                      <span className="text-base-content/70 text-helper">{row.category}</span>
                                  </td>
                                  <td className="font-mono text-xs whitespace-nowrap">
                                      {row.case_reference ?? <span className="text-base-content/50">—</span>}
                                  </td>
                                  <td>
                                      <OutcomeBadge row={row} />
                                  </td>
                                  <td className="font-mono text-xs tabular-nums">{row.ip_address ?? '—'}</td>
                                  <td className="text-end">
                                      <button
                                          type="button"
                                          className="btn btn-ghost btn-xs capitalize"
                                          onClick={() => onOpen(row)}
                                          aria-label={`View details of ${row.action} at ${row.occurred_at}`}
                                      >
                                          Details
                                          <ChevronRight className="size-3.5" aria-hidden="true" />
                                      </button>
                                  </td>
                              </tr>
                          ))}
                </tbody>
            </table>
        </div>
    );
}
