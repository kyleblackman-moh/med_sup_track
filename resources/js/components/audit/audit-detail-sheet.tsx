import { OutcomeBadge } from '@/components/audit/outcome-badge';
import { type AuditDetail, type AuditMetadataValue, type AuditRouteBase, type AuditRow, REDACTED } from '@/components/audit/types';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CircleAlert, EyeOff, RotateCw } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useState } from 'react';

interface Props {
    routeBase: AuditRouteBase;
    row: AuditRow | null;
    onClose: () => void;
}

type Load = { state: 'loading' } | { state: 'error'; message: string } | { state: 'ready'; detail: AuditDetail };

function Redacted() {
    return (
        <span className="badge badge-ghost badge-sm font-sans">
            <EyeOff className="size-3.5" aria-hidden="true" />
            Redacted
        </span>
    );
}

/** One metadata value: primitives inline, objects and lists nested. "[redacted]" is shown as a badge. */
function MetadataValue({ value }: { value: AuditMetadataValue }): ReactNode {
    if (value === REDACTED) return <Redacted />;
    if (value === null) return <span className="text-base-content/50">null</span>;
    if (typeof value === 'boolean') return <span>{value ? 'true' : 'false'}</span>;
    if (typeof value === 'number') return <span className="tabular-nums">{value}</span>;
    if (typeof value === 'string') return <span className="break-all whitespace-pre-wrap">{value}</span>;
    if (Array.isArray(value)) {
        if (value.length === 0) return <span className="text-base-content/50">[]</span>;
        return (
            <ol className="border-base-300 grid gap-1 border-l ps-3">
                {value.map((item, i) => (
                    <li key={i}>
                        <MetadataValue value={item} />
                    </li>
                ))}
            </ol>
        );
    }
    return <MetadataList data={value} />;
}

function MetadataList({ data }: { data: { [key: string]: AuditMetadataValue } }) {
    const entries = Object.entries(data);
    if (entries.length === 0) return <span className="text-base-content/50">{'{}'}</span>;

    return (
        <dl className="border-base-300 grid gap-x-3 gap-y-1.5 border-l ps-3 sm:grid-cols-[minmax(7rem,auto)_1fr]">
            {entries.map(([key, value]) => (
                <div key={key} className="contents">
                    <dt className="text-base-content/70 break-all">{key}</dt>
                    <dd className="min-w-0">
                        <MetadataValue value={value} />
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-base-content/70 text-helper capitalize">{label}</dt>
            <dd className="min-w-0 text-sm break-all">{children}</dd>
        </div>
    );
}

/**
 * IT-ADMIN: one audit event, opened from the ledger in a sheet on the right
 * so the list keeps its place. The detail is fetched on open from the same
 * door the page came through (admin.audit.show or audit.show); its metadata
 * is already redacted by the server (AuditRedactor). Read-only.
 */
export function AuditDetailSheet({ routeBase, row, onClose }: Props) {
    const [load, setLoad] = useState<Load>({ state: 'loading' });

    const fetchDetail = useCallback(
        async (id: number, signal?: AbortSignal) => {
            setLoad({ state: 'loading' });
            try {
                const response = await fetch(route(`${routeBase}.show`, id), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal,
                });
                if (!response.ok) {
                    setLoad({
                        state: 'error',
                        message: response.status === 404 ? 'This event is no longer available to you.' : 'The event details could not be loaded.',
                    });
                    return;
                }
                setLoad({ state: 'ready', detail: (await response.json()) as AuditDetail });
            } catch (e) {
                if ((e as Error).name !== 'AbortError') setLoad({ state: 'error', message: 'The event details could not be loaded.' });
            }
        },
        [routeBase],
    );

    useEffect(() => {
        if (!row) return;
        const controller = new AbortController();
        void fetchDetail(row.id, controller.signal);
        return () => controller.abort();
    }, [row, fetchDetail]);

    const detail = load.state === 'ready' ? load.detail : null;
    const metadata = detail?.metadata;
    const metadataEmpty = !metadata || (Array.isArray(metadata) ? metadata.length === 0 : Object.keys(metadata).length === 0);

    return (
        <Sheet open={row !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent side="right" className="w-full max-w-xl gap-5 overflow-y-auto">
                {row && (
                    <>
                        <SheetHeader className="pe-8 text-left">
                            <SheetTitle className="font-mono text-base break-all">{row.action}</SheetTitle>
                            <SheetDescription className="text-base-content/70 text-sm">
                                <span className="font-mono tabular-nums">{row.occurred_at}</span> · Port of Spain · event #{row.id}
                            </SheetDescription>
                        </SheetHeader>

                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Fact label="Actor">{row.actor}</Fact>
                            <Fact label="Outcome">
                                <OutcomeBadge row={row} />
                            </Fact>
                            <Fact label="Category">{row.category}</Fact>
                            <Fact label="Case">{row.case_reference ? <span className="font-mono">{row.case_reference}</span> : '—'}</Fact>
                            <Fact label="Subject">{row.subject ? <span className="font-mono">{row.subject}</span> : '—'}</Fact>
                            <Fact label="Source IP">{row.ip_address ? <span className="font-mono tabular-nums">{row.ip_address}</span> : '—'}</Fact>
                            <Fact label="Request ID">{row.request_id ? <span className="font-mono">{row.request_id}</span> : '—'}</Fact>
                            {detail && <Fact label="Actor Type">{detail.actor_type ?? '—'}</Fact>}
                            {detail && (
                                <div className="sm:col-span-2">
                                    <Fact label="Client">
                                        {detail.user_agent ? <span className="font-mono text-xs">{detail.user_agent}</span> : '—'}
                                    </Fact>
                                </div>
                            )}
                        </dl>

                        <section aria-labelledby="audit-context-heading" className="grid gap-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 id="audit-context-heading" className="text-sm font-semibold capitalize">
                                    Recorded Context
                                </h3>
                                <span className="text-base-content/70 text-helper inline-flex items-center gap-1">
                                    <EyeOff className="size-3.5" aria-hidden="true" />
                                    Secrets, codes and links are redacted
                                </span>
                            </div>

                            {load.state === 'loading' && (
                                <div className="grid gap-2" aria-busy="true" aria-label="Loading the recorded context">
                                    <div className="skeleton h-4 w-3/4" />
                                    <div className="skeleton h-4 w-1/2" />
                                    <div className="skeleton h-4 w-2/3" />
                                </div>
                            )}

                            {load.state === 'error' && (
                                <div role="alert" className="alert alert-soft alert-error text-sm">
                                    <CircleAlert className="size-5" aria-hidden="true" />
                                    <span>{load.message}</span>
                                    <button type="button" className="btn btn-sm capitalize" onClick={() => void fetchDetail(row.id)}>
                                        <RotateCw className="size-4" aria-hidden="true" />
                                        Try Again
                                    </button>
                                </div>
                            )}

                            {detail &&
                                (metadataEmpty ? (
                                    <p className="text-base-content/70 text-sm">No context was recorded with this event.</p>
                                ) : (
                                    <div className="bg-base-200 rounded-box overflow-x-auto p-3 font-mono text-xs">
                                        {Array.isArray(metadata) ? <MetadataValue value={metadata} /> : <MetadataList data={metadata!} />}
                                    </div>
                                ))}
                        </section>

                        <SheetFooter>
                            <button type="button" className="btn btn-sm capitalize" onClick={onClose}>
                                Close
                            </button>
                        </SheetFooter>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
