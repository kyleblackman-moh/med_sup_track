import { type FailedJob } from '@/components/admin/health/types';
import { DataTable, DataTableCell, DataTableEmpty, DataTableHeaderCell, DataTableRow, DataTableRowHeader } from '@/components/ui/data-table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { CircleCheck, Info, RotateCcw } from 'lucide-react';
import { useRef, useState } from 'react';

/** The last part of a class name ("App\Jobs\SendLetter" → "SendLetter"); the full name stays in the title. */
function shortClass(name: string): string {
    const parts = name.split('\\');
    return parts[parts.length - 1] || name;
}

interface Props {
    jobs: FailedJob[];
    /** abilities.retry / abilities.retry_jobs from the server: show the Retry action. */
    canRetry: boolean;
    caption: string;
}

/**
 * IT-ADMIN Part 3: failed queue jobs (job class, exception class, queue and
 * when it failed; never the payload or the message). Retry opens a confirm
 * dialog and posts admin.jobs.retry. Without a recent password confirmation
 * the server sends the user to Confirm Password and back here; nothing runs
 * until they retry again.
 */
export function FailedJobsTable({ jobs, canRetry, caption }: Props) {
    const [target, setTarget] = useState<FailedJob | null>(null);
    const [busy, setBusy] = useState(false);
    const cancelRef = useRef<HTMLButtonElement>(null);

    const close = () => {
        if (!busy) setTarget(null);
    };

    const retry = () => {
        if (!target) return;
        router.post(
            route('admin.jobs.retry', target.uuid),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setTarget(null);
                },
            },
        );
    };

    if (jobs.length === 0) {
        return (
            <DataTableEmpty
                message={
                    <span className="inline-flex items-center gap-2">
                        <CircleCheck className="text-success size-4" aria-hidden="true" />
                        No failed queue jobs. Every queued job has run or is waiting its turn.
                    </span>
                }
            />
        );
    }

    return (
        <>
            <DataTable caption={caption}>
                <thead>
                    <tr>
                        <DataTableHeaderCell className="capitalize">Job</DataTableHeaderCell>
                        <DataTableHeaderCell className="capitalize">Exception</DataTableHeaderCell>
                        <DataTableHeaderCell className="capitalize max-md:hidden">Queue</DataTableHeaderCell>
                        <DataTableHeaderCell className="capitalize">Failed</DataTableHeaderCell>
                        {canRetry && (
                            <DataTableHeaderCell>
                                <span className="sr-only">Actions</span>
                            </DataTableHeaderCell>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {jobs.map((job) => (
                        <DataTableRow key={job.uuid}>
                            <DataTableRowHeader>
                                <span className="font-mono text-xs font-medium" title={job.job}>
                                    {shortClass(job.job)}
                                </span>
                            </DataTableRowHeader>
                            <DataTableCell>
                                <span className="font-mono text-xs break-all" title={job.exception}>
                                    {shortClass(job.exception)}
                                </span>
                            </DataTableCell>
                            <DataTableCell className="max-md:hidden">
                                <span className="badge badge-ghost badge-sm">{job.queue}</span>
                                <span className="text-base-content/70 text-helper ml-2">{job.connection}</span>
                            </DataTableCell>
                            <DataTableCell className="whitespace-nowrap tabular-nums">{job.failed_at}</DataTableCell>
                            {canRetry && (
                                <DataTableCell className="text-right">
                                    <button type="button" className="btn btn-outline btn-sm capitalize" onClick={() => setTarget(job)}>
                                        <RotateCcw className="size-4" aria-hidden="true" />
                                        Retry
                                        <span className="sr-only"> {shortClass(job.job)}</span>
                                    </button>
                                </DataTableCell>
                            )}
                        </DataTableRow>
                    ))}
                </tbody>
            </DataTable>

            <Dialog open={target !== null} onOpenChange={(open) => !open && close()}>
                <DialogContent
                    onOpenAutoFocus={(e) => {
                        e.preventDefault();
                        cancelRef.current?.focus();
                    }}
                >
                    {target && (
                        <>
                            <DialogHeader>
                                <DialogTitle className="capitalize">Retry This Job?</DialogTitle>
                                <DialogDescription>The job goes back on its queue and runs again. Its failed record is removed.</DialogDescription>
                            </DialogHeader>

                            <dl className="border-base-300 rounded-box grid gap-2 border p-3 text-sm">
                                <div className="flex flex-wrap justify-between gap-2">
                                    <dt className="text-base-content/70 capitalize">Job</dt>
                                    <dd className="font-mono text-xs break-all">{target.job}</dd>
                                </div>
                                <div className="flex flex-wrap justify-between gap-2">
                                    <dt className="text-base-content/70 capitalize">Exception</dt>
                                    <dd className="font-mono text-xs break-all">{target.exception}</dd>
                                </div>
                                <div className="flex flex-wrap justify-between gap-2">
                                    <dt className="text-base-content/70 capitalize">Queue</dt>
                                    <dd>
                                        {target.queue} ({target.connection})
                                    </dd>
                                </div>
                                <div className="flex flex-wrap justify-between gap-2">
                                    <dt className="text-base-content/70 capitalize">Failed</dt>
                                    <dd className="tabular-nums">{target.failed_at}</dd>
                                </div>
                            </dl>

                            <div role="note" className="alert alert-soft text-sm">
                                <Info className="size-4 shrink-0" aria-hidden="true" />
                                <span>
                                    If the cause is not fixed, the job may fail again. You may be asked for your password first; then retry again.
                                </span>
                            </div>

                            <DialogFooter>
                                <button ref={cancelRef} type="button" className="btn btn-sm capitalize" onClick={close} disabled={busy}>
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-primary btn-sm capitalize"
                                    onClick={retry}
                                    disabled={busy}
                                    aria-busy={busy || undefined}
                                >
                                    <RotateCcw className="size-4" aria-hidden="true" />
                                    {busy ? 'Retrying…' : 'Retry Job'}
                                </button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
