import { StatusBadge } from '@/components/cases/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type StatusTone } from '@/types/cases';
import { Link, router } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { type MouseEvent, useState } from 'react';

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

function openCase(e: MouseEvent<HTMLTableRowElement>, item: WorkQueueItem) {
    if ((e.target as HTMLElement).closest('a, button, input, select, textarea, label')) return;
    if (window.getSelection()?.toString()) return;
    router.visit(route('cases.show', item.case_id));
}

/** "Needs my action": built on the server (WorkQueue), oldest first. Each row opens the case, where the action is taken. */
export function WorkQueue({ queue }: { queue: WorkQueueData }) {
    const [step, setStep] = useState<string | null>(null);
    const groups = queue.groups.filter((g) => g.count > 0);
    const items = step ? queue.items.filter((i) => i.step === step) : queue.items;
    const cell = 'h-12 border-b border-border px-3 whitespace-nowrap';

    const chip = (key: string | null, label: string, count: number) => (
        <button
            key={key ?? 'all'}
            type="button"
            aria-pressed={step === key}
            onClick={() => setStep(key)}
            className={cn(
                'focus-visible:ring-ring inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-sm outline-none focus-visible:ring-2',
                step === key ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card hover:bg-muted/60',
            )}
        >
            {label}
            <span className="tabular-nums opacity-80">{count}</span>
        </button>
    );

    return (
        <section aria-labelledby="work-queue-heading" className="space-y-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="work-queue-heading" className="text-base font-semibold">
                    Work queue
                </h2>
                <span className="text-muted-foreground text-sm">Oldest first</span>
            </div>

            {groups.length > 0 && (
                <div className="flex flex-wrap gap-2" role="group" aria-label="Filter the work queue">
                    {chip(null, 'All', queue.items.length)}
                    {groups.map((g) => chip(g.key, g.label, g.count))}
                </div>
            )}

            <div className="bg-card border-border rounded-lg border shadow-sm">
                {items.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                        <CircleCheck className="text-status-success-foreground size-8" aria-hidden="true" />
                        <p className="text-sm font-medium">Nothing needs your action right now.</p>
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto md:block">
                            <table className="w-full border-separate border-spacing-0 text-sm">
                                <caption className="sr-only">Cases waiting on you, oldest first</caption>
                                <thead>
                                    <tr className="text-muted-foreground text-left text-xs">
                                        {['Action', 'Reference', 'Item', 'Organisation', 'Status', 'Waiting'].map((h, i) => (
                                            <th
                                                key={h}
                                                scope="col"
                                                className={cn(
                                                    'bg-muted/60 border-border h-10 border-b px-3 font-medium whitespace-nowrap',
                                                    i === 0 && 'rounded-tl-lg',
                                                    i === 5 && 'rounded-tr-lg text-right',
                                                    h === 'Item' && 'w-full',
                                                    h === 'Organisation' && 'hidden lg:table-cell',
                                                )}
                                            >
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.map((item) => (
                                        <tr
                                            key={item.id}
                                            onClick={(e) => openCase(e, item)}
                                            className="hover:bg-muted/60 focus-within:bg-muted/60 cursor-pointer"
                                        >
                                            <td className={cell}>
                                                <Button asChild size="sm" className="h-8">
                                                    <Link href={route('cases.show', item.case_id)}>
                                                        {item.action}
                                                        <span className="sr-only"> for {item.reference_no ?? 'this case'}</span>
                                                    </Link>
                                                </Button>
                                            </td>
                                            <td className={cn(cell, 'font-mono text-[13px] font-medium')}>{item.reference_no ?? '—'}</td>
                                            <td className={cn(cell, 'max-w-0 truncate')} title={item.item_description ?? undefined}>
                                                {item.item_description ?? '—'}
                                            </td>
                                            <td className={cn(cell, 'hidden lg:table-cell')}>{item.organisation ?? '—'}</td>
                                            <td className={cell}>
                                                <StatusBadge label={item.status_label} tone={item.status_tone} />
                                            </td>
                                            <td className={cn(cell, 'text-right tabular-nums')}>{waitingLabel(item.days_waiting)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="divide-border divide-y md:hidden">
                            {items.map((item) => (
                                <li key={item.id}>
                                    <Link
                                        href={route('cases.show', item.case_id)}
                                        className="hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:ring-ring block space-y-1 p-4 outline-none focus-visible:ring-2 focus-visible:ring-inset"
                                    >
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="text-primary text-sm font-semibold">{item.action}</span>
                                            <span className="text-xs tabular-nums">{waitingLabel(item.days_waiting)}</span>
                                        </span>
                                        <span className="font-mono text-[13px] font-medium">{item.reference_no ?? '—'}</span>
                                        <span className="text-muted-foreground block truncate text-sm">{item.item_description ?? '—'}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
                {queue.truncated && (
                    <p className="text-muted-foreground border-border border-t px-3 py-2.5 text-sm">
                        Showing the oldest cases only. Use{' '}
                        <Link href={route('cases.index')} className="text-primary underline-offset-2 hover:underline">
                            File tracking
                        </Link>{' '}
                        for the full list.
                    </p>
                )}
            </div>
        </section>
    );
}
