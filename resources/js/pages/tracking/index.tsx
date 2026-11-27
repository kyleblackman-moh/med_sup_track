import { holdersText } from '@/components/cases/file-journey';
import { PageContent, PageHeader } from '@/components/page-header';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusPill } from '@/components/ui/status-pill';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type FileTrackingRow, type FileTrackingTimeline } from '@/types/tracking';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { Fragment, useState } from 'react';

interface Props {
    /** Every case the user can see, longest at the current stage first (FileTracking::rows). */
    rows: FileTrackingRow[];
    /** The opened row's timeline, sent only on the partial reload that opens it; null otherwise. */
    timeline?: FileTrackingTimeline | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'File Tracking', href: '/tracking' }];

const HEADERS = ['Reference', 'Item', 'Current Stage', 'Current Holder(s)', 'Time at Current Stage', 'Total Time', 'Times Returned'] as const;

/** Every stage visit of one case, repeats included ("With Legal (2nd time)"), as the server sends them. */
function StageTimeline({ timeline }: { timeline: FileTrackingTimeline | null | undefined }) {
    if (!timeline) {
        return (
            <div className="space-y-2" aria-busy="true">
                <div className="skeleton h-4 w-1/2" />
                <div className="skeleton h-4 w-2/3" />
                <div className="skeleton h-4 w-1/3" />
            </div>
        );
    }
    if (timeline.stages.length === 0) {
        return <p className="text-base-content/70 text-sm">No stages yet.</p>;
    }

    return (
        <div className="max-w-full overflow-x-auto">
            <table className="table-xs table">
                <caption className="sr-only">Stage timeline</caption>
                <thead>
                    <tr className="capitalize">
                        <th scope="col">Stage</th>
                        <th scope="col">Unit</th>
                        <th scope="col">Holder(s)</th>
                        <th scope="col">Entered</th>
                        <th scope="col">Left</th>
                        <th scope="col" className="text-right">
                            Duration
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {timeline.stages.map((stage, i) => (
                        <tr key={`${stage.status}-${i}`} className={stage.open ? 'font-medium' : undefined}>
                            <td className="whitespace-nowrap">{stage.label}</td>
                            <td className="whitespace-nowrap">{stage.unit_label ?? '—'}</td>
                            <td>{holdersText(stage)}</td>
                            <td className="whitespace-nowrap tabular-nums">
                                <time dateTime={stage.entered_at_iso ?? undefined}>{stage.entered_at}</time>
                            </td>
                            <td className="whitespace-nowrap tabular-nums">
                                {stage.left_at && stage.left_at_iso ? <time dateTime={stage.left_at_iso}>{stage.left_at}</time> : 'Now'}
                            </td>
                            <td className="text-right whitespace-nowrap tabular-nums">{stage.duration}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * Backlog, 2026-11-25: the File Tracking page. Every case the user can see,
 * longest at its current stage first (the server's order): where it is, who
 * holds it, how long it has been there and in all, and how many times it came
 * back. A case in review shows the gate's three ticks. Opening a row loads its
 * stage timeline (a partial reload of `timeline`, ?open=<id>, through the same
 * checks). Plain elapsed time, no lateness colours.
 */
export default function FileTrackingIndex({ rows, timeline }: Props) {
    const [openId, setOpenId] = useState<string | null>(timeline?.id ?? null);

    const toggle = (id: string) => {
        const next = openId === id ? null : id;
        setOpenId(next);
        if (next !== null && timeline?.id !== next) {
            router.reload({ only: ['timeline'], data: { open: next } });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="File Tracking" />

            <PageContent className="space-y-3 px-4 py-4 md:p-4 lg:px-6">
                <PageHeader
                    title="File Tracking"
                    titleClassName="capitalize"
                    subtitle={
                        <span className="tabular-nums">
                            {rows.length} {rows.length === 1 ? 'case' : 'cases'}, longest at the current stage first
                        </span>
                    }
                />

                <div className="card card-border bg-base-100 min-w-0">
                    {rows.length === 0 ? (
                        <EmptyState message="No cases to track yet." />
                    ) : (
                        <div className="max-w-full min-w-0 overflow-x-auto">
                            <table className="table-sm table">
                                <caption className="sr-only">File Tracking. Open a row to see its stage timeline.</caption>
                                <thead>
                                    <tr className="capitalize">
                                        {HEADERS.map((h) => (
                                            <th key={h} scope="col" className={cn('whitespace-nowrap', h === 'Times Returned' && 'text-right')}>
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => {
                                        const open = openId === row.id;
                                        const panelId = `tracking-${row.id}`;

                                        return (
                                            <Fragment key={row.id}>
                                                <tr className={cn('align-top', open && 'bg-base-200')}>
                                                    <td className="whitespace-nowrap">
                                                        <div className="flex items-center gap-1">
                                                            <button
                                                                type="button"
                                                                className="btn btn-ghost btn-xs btn-square"
                                                                aria-expanded={open}
                                                                aria-controls={panelId}
                                                                aria-label={`${open ? 'Hide' : 'Show'} the stage timeline of ${row.reference ?? 'this case'}`}
                                                                onClick={() => toggle(row.id)}
                                                            >
                                                                <ChevronDown
                                                                    className={cn('size-4 motion-safe:transition-transform', open && 'rotate-180')}
                                                                    aria-hidden="true"
                                                                />
                                                            </button>
                                                            <Link
                                                                href={route('cases.show', row.id)}
                                                                className="link link-hover font-mono font-medium"
                                                            >
                                                                {row.reference ?? '—'}
                                                            </Link>
                                                        </div>
                                                    </td>
                                                    <td className="min-w-48">
                                                        <span className="block">{row.item ?? '—'}</span>
                                                    </td>
                                                    <td className="whitespace-nowrap">
                                                        <StatusPill label={row.stage} tone={row.status_tone} inColumn className="capitalize" />
                                                    </td>
                                                    <td className="min-w-40">{row.holder_label ?? '—'}</td>
                                                    <td className="whitespace-nowrap tabular-nums">{row.at_stage}</td>
                                                    <td className="whitespace-nowrap tabular-nums">{row.since_submission}</td>
                                                    <td className="text-right tabular-nums">{row.times_returned}</td>
                                                </tr>
                                                {open && (
                                                    <tr id={panelId} className="bg-base-200">
                                                        <td colSpan={HEADERS.length} className="px-4 pb-4">
                                                            <StageTimeline timeline={timeline?.id === row.id ? timeline : null} />
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </PageContent>
        </AppLayout>
    );
}
