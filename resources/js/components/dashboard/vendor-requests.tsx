import { StatusPill } from '@/components/ui/status-pill';
import { type VendorWidgets } from '@/types/dashboard';
import { type RequestSummary } from '@/types/requests';
import { Link } from '@inertiajs/react';
import { FilePen, FileText, Send } from 'lucide-react';
import { useState } from 'react';

const TZ = 'America/Port_of_Spain';

function day(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-TT', { timeZone: TZ, day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}

const btn = 'btn btn-sm whitespace-nowrap capitalize transition-transform active:scale-[0.97]';

function Empty({ title, text }: { title: string; text: string }) {
    return (
        <div className="flex flex-col items-center gap-2 px-6 py-10 text-center">
            <FileText className="text-base-content/40 size-8" aria-hidden="true" />
            <p className="font-medium">{title}</p>
            <p className="text-base-content/70 max-w-sm text-sm">{text}</p>
        </div>
    );
}

function Rows({ items, kind }: { items: RequestSummary[]; kind: 'drafts' | 'requests' }) {
    const action = (r: RequestSummary) =>
        kind === 'drafts' && r.can.edit ? (
            <Link href={route('requests.edit', r.id)} className={btn}>
                <FilePen className="size-3.5" aria-hidden="true" />
                Continue draft
            </Link>
        ) : (
            <Link href={route('requests.show', r.id)} className="btn btn-ghost btn-sm capitalize">
                View
            </Link>
        );

    return (
        <>
            <div className="hidden max-w-full overflow-x-auto md:block">
                <table className="table-sm table">
                    <caption className="sr-only">{kind === 'drafts' ? 'Your drafts' : 'Your submitted requests'}</caption>
                    <thead>
                        <tr className="capitalize">
                            {kind === 'requests' && <th>Reference</th>}
                            <th className="w-full">Item</th>
                            <th>Status</th>
                            <th className="text-right">Last updated</th>
                            <th>
                                <span className="sr-only">Action</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="motion-safe:transition-opacity motion-safe:duration-300 motion-safe:starting:opacity-0">
                        {items.map((r) => (
                            <tr key={r.id} className="hover:bg-base-200 transition-colors">
                                {kind === 'requests' && (
                                    <td className="font-mono text-[13px] font-medium whitespace-nowrap">{r.reference_no ?? '—'}</td>
                                )}
                                <td className="max-w-0 min-w-44">
                                    <span className="block truncate" title={r.item_description ?? undefined}>
                                        {r.item_description ?? r.procurement_type.name}
                                    </span>
                                </td>
                                <td>
                                    <StatusPill label={r.status_label} tone={r.status_tone} />
                                </td>
                                <td className="text-right whitespace-nowrap tabular-nums">{day(r.updated_at)}</td>
                                <td className="text-right">{action(r)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <ul className="list md:hidden">
                {items.map((r) => (
                    <li key={r.id} className="list-row items-center">
                        <div className="list-col-grow min-w-0">
                            {kind === 'requests' && <div className="font-mono text-[13px] font-medium">{r.reference_no ?? '—'}</div>}
                            <div className="truncate text-sm">{r.item_description ?? r.procurement_type.name}</div>
                            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                <StatusPill label={r.status_label} tone={r.status_tone} />
                                <span className="tabular-nums">{day(r.updated_at)}</span>
                            </div>
                        </div>
                        {action(r)}
                    </li>
                ))}
            </ul>
        </>
    );
}

/** The vendor's main card: their drafts and submitted requests, nothing internal. */
export function VendorRequests({ widgets }: { widgets: VendorWidgets }) {
    const [tab, setTab] = useState<'drafts' | 'requests'>(widgets.drafts.total > 0 ? 'drafts' : 'requests');
    const tabButton = (key: 'drafts' | 'requests', label: string, Icon: typeof FilePen, total: number) => (
        <button
            type="button"
            role="tab"
            id={`vreq-tab-${key}`}
            aria-controls="vreq-panel"
            aria-selected={tab === key}
            className={tab === key ? 'tab tab-active gap-2 capitalize' : 'tab gap-2 capitalize'}
            onClick={() => setTab(key)}
        >
            <Icon className="size-4" aria-hidden="true" />
            {label}
            <span className="badge badge-ghost badge-sm tabular-nums">{total}</span>
        </button>
    );
    const list = widgets[tab];

    return (
        <section
            aria-labelledby="vreq-heading"
            className="card bg-base-100 border-base-300 min-w-0 border transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body min-w-0 gap-3 p-0">
                <h2 id="vreq-heading" className="card-title px-4 pt-4 text-base capitalize">
                    <FileText className="text-primary size-4" aria-hidden="true" />
                    Your requests
                </h2>
                <div role="tablist" aria-label="Requests" className="tabs tabs-border px-2">
                    {tabButton('drafts', 'Drafts', FilePen, widgets.drafts.total)}
                    {tabButton('requests', 'Submitted', Send, widgets.requests.total)}
                </div>
                <div id="vreq-panel" role="tabpanel" aria-labelledby={`vreq-tab-${tab}`}>
                    {list.items.length > 0 ? (
                        <Rows items={list.items} kind={tab} />
                    ) : tab === 'drafts' ? (
                        <Empty title="No drafts" text="Start a new request when you are ready. Drafts are saved as you go." />
                    ) : (
                        <Empty title="No submitted requests" text="Requests you submit appear here with their current status." />
                    )}
                </div>
                <div className="border-base-300 text-base-content/70 border-t px-4 py-3 text-sm">
                    {list.total > list.items.length ? `Showing the latest ${list.items.length} of ${list.total}. ` : ''}
                    All your requests are in{' '}
                    <Link href={route('requests.index')} className="link">
                        Requests
                    </Link>
                    .
                </div>
            </div>
        </section>
    );
}
