import { StatusPill } from '@/components/ui/status-pill';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type StatusTone } from '@/types/cases';
import { Link } from '@inertiajs/react';
import { CircleCheck, Inbox } from 'lucide-react';
import { useState } from 'react';

export interface WorkQueueItem {
    id: string;
    case_id: string;
    step: string;
    action: string;
    reference_no: string | null;
    item_description: string | null;
    organisation: string | null;
    status_label: string;
    status_tone: StatusTone;
    waiting_since: string | null;
    days_waiting: number | null;
}

export interface WorkQueueData {
    groups: { key: string; label: string; count: number }[];
    items: WorkQueueItem[];
    truncated: boolean;
}

function waitingLabel(days: number | null): string {
    if (days === null) return '—';
    if (days === 0) return 'Today';
    return days === 1 ? '1 day' : `${days} days`;
}

const actionBtn = 'btn btn-sm whitespace-nowrap capitalize transition-transform active:scale-[0.97]';

/** "Needs your action": built on the server (WorkQueue), oldest first. Each row opens the case, where the action is taken. */
export function WorkQueue({ queue }: { queue: WorkQueueData }) {
    const [step, setStep] = useState<string | null>(null);
    const groups = queue.groups.filter((g) => g.count > 0);
    const items = step ? queue.items.filter((i) => i.step === step) : queue.items;

    const chip = (key: string | null, label: string, count: number) => (
        <button
            key={key ?? 'all'}
            type="button"
            aria-pressed={step === key}
            onClick={() => setStep(key)}
            className={cn('btn btn-sm gap-1.5 capitalize transition-colors', step === key ? 'btn-neutral' : 'btn-ghost border-base-300 border')}
        >
            {label}
            <span className="badge badge-sm badge-ghost tabular-nums">{count}</span>
        </button>
    );

    return (
        <section
            aria-labelledby="work-queue-heading"
            className="card bg-base-100 border-base-300 min-w-0 border transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body min-w-0 gap-3 p-0">
                <div className="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                    <h2 id="work-queue-heading" className="card-title text-base capitalize">
                        <Inbox className="text-primary size-4" aria-hidden="true" />
                        Needs your action
                    </h2>
                    <span className="text-base-content/60 text-xs capitalize">Oldest first</span>
                </div>

                {groups.length > 1 && (
                    <div className="flex flex-wrap gap-2 px-4" role="group" aria-label="Filter the work queue">
                        {chip(null, 'All', queue.items.length)}
                        {groups.map((g) => chip(g.key, g.label, g.count))}
                    </div>
                )}

                {items.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                        <CircleCheck className="text-success size-8" aria-hidden="true" />
                        <p className="font-medium">Nothing needs your action right now.</p>
                        <p className="text-base-content/70 max-w-sm text-sm">New work appears here as soon as a case reaches your desk.</p>
                    </div>
                ) : (
                    <>
                        <div className="hidden max-w-full overflow-x-auto md:block">
                            <table className="table-sm table">
                                <caption className="sr-only">Cases waiting on you, oldest first</caption>
                                <thead>
                                    <tr className="capitalize">
                                        <th>Action</th>
                                        <th>Reference</th>
                                        <th className="w-full">Item</th>
                                        <th>Status</th>
                                        <th className="text-right">Waiting</th>
                                    </tr>
                                </thead>
                                <tbody
                                    className="motion-safe:transition-opacity motion-safe:duration-300 motion-safe:starting:opacity-0"
                                    key={step ?? 'all'}
                                >
                                    {items.map((item) => (
                                        // The action link covers the row (rowLink); no onClick on the row.
                                        <tr key={item.id} className="hover:bg-base-200 focus-within:bg-base-200 relative transition-colors">
                                            <td>
                                                <Link href={route('cases.show', item.case_id)} className={cn(actionBtn, rowLink)}>
                                                    {item.action}
                                                    <span className="sr-only"> for {item.reference_no ?? 'this case'}</span>
                                                </Link>
                                            </td>
                                            <td className="font-mono text-[13px] font-medium whitespace-nowrap">{item.reference_no ?? '—'}</td>
                                            <td className="max-w-0 min-w-44">
                                                <span className="block truncate" title={item.item_description ?? undefined}>
                                                    {item.item_description ?? '—'}
                                                </span>
                                                {item.organisation && (
                                                    <span className="text-base-content/60 block truncate text-xs">{item.organisation}</span>
                                                )}
                                            </td>
                                            <td>
                                                <StatusPill label={item.status_label} tone={item.status_tone} />
                                            </td>
                                            <td className="text-right whitespace-nowrap tabular-nums">{waitingLabel(item.days_waiting)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="list md:hidden">
                            {items.map((item) => (
                                <li key={item.id} className="list-row items-center">
                                    <div className="list-col-grow min-w-0">
                                        <div className="font-mono text-[13px] font-medium">{item.reference_no ?? '—'}</div>
                                        <div className="truncate text-sm">{item.item_description ?? '—'}</div>
                                        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                            <StatusPill label={item.status_label} tone={item.status_tone} />
                                            <span className="tabular-nums">{waitingLabel(item.days_waiting)}</span>
                                        </div>
                                    </div>
                                    <Link href={route('cases.show', item.case_id)} className={actionBtn}>
                                        {item.action}
                                        <span className="sr-only"> for {item.reference_no ?? 'this case'}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                <div className="border-base-300 text-base-content/70 border-t px-4 py-3 text-sm">
                    {queue.truncated ? 'Showing the oldest cases only. ' : ''}Everything else is in{' '}
                    <Link href={route('cases.index')} className="link">
                        File tracking
                    </Link>
                    .
                </div>
            </div>
        </section>
    );
}
