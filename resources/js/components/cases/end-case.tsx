import { ActionPanel, actionTextareaClass } from '@/components/cases/action-panel';
import InputError from '@/components/input-error';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type CaseEnded } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { Ban, CircleSlash, OctagonX, TriangleAlert } from 'lucide-react';
import { type FormEvent, useRef, useState } from 'react';

const LABEL = 'label text-base-content text-sm font-medium capitalize';
// EndCase::REASON_MAX and EndCase::MESSAGE_MAX.
const REASON_MAX = 2000;
const MESSAGE_MAX = 2000;

type Mode = 'withdraw' | 'reject';

const COPY: Record<Mode, { title: string; button: string; busy: string; result: string; route: string }> = {
    withdraw: { title: 'Withdraw this case?', button: 'Withdraw Case', busy: 'Withdrawing…', result: 'Withdrawn', route: 'cases.withdraw' },
    reject: { title: 'Reject this case?', button: 'Reject Case', busy: 'Rejecting…', result: 'Rejected', route: 'cases.reject-final' },
};

interface DialogProps {
    mode: Mode | null;
    caseId: string;
    reference: string | null;
    organisation: string | null;
    onClose: () => void;
}

/**
 * TERMINAL-1: the one confirmation for ending a case (withdraw, T15, or final
 * rejection on the PS's instruction, T16). It states that this cannot be
 * undone and names the case and vendor. The reason is internal (case
 * history); the optional message is what the vendor sees. Cancel has the
 * focus when it opens; buttons at the bottom, the irreversible one last.
 */
function EndCaseDialog({ mode, caseId, reference, organisation, onClose }: DialogProps) {
    const cancelRef = useRef<HTMLButtonElement>(null);
    const form = useForm<{ reason: string; vendor_message: string; on_ps_instruction: boolean }>({
        reason: '',
        vendor_message: '',
        on_ps_instruction: false,
    });
    const copy = mode ? COPY[mode] : null;
    const ready = form.data.reason.trim().length > 0 && (mode !== 'reject' || form.data.on_ps_instruction);

    const close = () => {
        if (form.processing) return;
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!copy || !ready) return;
        // The PS flag is sent for a rejection only; a withdrawal has none.
        form.transform((data) => (mode === 'reject' ? data : { reason: data.reason, vendor_message: data.vendor_message }));
        form.post(route(copy.route, caseId), { preserveScroll: true, onSuccess: () => close() });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open={mode !== null} onOpenChange={(open) => !open && close()}>
            <DialogContent
                onOpenAutoFocus={(e) => {
                    e.preventDefault();
                    cancelRef.current?.focus();
                }}
            >
                {copy && (
                    <form onSubmit={submit} className="grid gap-4" noValidate>
                        <DialogHeader>
                            <DialogTitle className="capitalize">{copy.title}</DialogTitle>
                            <DialogDescription>
                                <span className="font-mono font-medium">{reference ?? 'This case'}</span>
                                {organisation && <> · {organisation}</>}
                            </DialogDescription>
                        </DialogHeader>

                        <div role="alert" className="alert alert-soft alert-error items-start text-sm">
                            <TriangleAlert className="size-5" aria-hidden="true" />
                            <p>
                                <span className="font-semibold">This cannot be undone.</span> The case is closed as {copy.result}; its open queries
                                and assignments end, no one can act on it again, and the vendor is told.
                            </p>
                        </div>

                        <fieldset className="fieldset py-0">
                            <label className={LABEL} htmlFor="end-case-reason">
                                Reason
                            </label>
                            <textarea
                                id="end-case-reason"
                                aria-required="true"
                                rows={3}
                                maxLength={REASON_MAX}
                                className={actionTextareaClass}
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                                aria-invalid={errors.reason ? true : undefined}
                                aria-describedby="end-case-reason-hint end-case-reason-error"
                            />
                            <p id="end-case-reason-hint" className="label text-helper">
                                Kept on the case history for staff. The vendor does not see it. Up to {REASON_MAX} characters.
                            </p>
                            <InputError id="end-case-reason-error" message={errors.reason} />
                        </fieldset>

                        <fieldset className="fieldset py-0">
                            <label className={LABEL} htmlFor="end-case-message">
                                Message to the Vendor (optional)
                            </label>
                            <textarea
                                id="end-case-message"
                                rows={3}
                                maxLength={MESSAGE_MAX}
                                className={actionTextareaClass}
                                value={form.data.vendor_message}
                                onChange={(e) => form.setData('vendor_message', e.target.value)}
                                aria-invalid={errors.vendor_message ? true : undefined}
                                aria-describedby="end-case-message-hint end-case-message-error"
                            />
                            <p id="end-case-message-hint" className="label text-helper">
                                The vendor sees this with the outcome on their request. Left empty, they see “No further details provided.” Up to{' '}
                                {MESSAGE_MAX} characters.
                            </p>
                            <InputError id="end-case-message-error" message={errors.vendor_message} />
                        </fieldset>

                        {mode === 'reject' && (
                            <fieldset className="fieldset py-0">
                                <label className="label text-base-content cursor-pointer items-start gap-2 text-sm whitespace-normal">
                                    <input
                                        type="checkbox"
                                        className="checkbox checkbox-sm mt-0.5"
                                        checked={form.data.on_ps_instruction}
                                        onChange={(e) => form.setData('on_ps_instruction', e.target.checked)}
                                        aria-invalid={errors.on_ps_instruction ? true : undefined}
                                        aria-describedby="end-case-ps-error"
                                    />
                                    The Permanent Secretary instructed this rejection.
                                </label>
                                <InputError id="end-case-ps-error" message={errors.on_ps_instruction} />
                            </fieldset>
                        )}

                        <InputError message={errors.status ?? errors.case} />

                        <DialogFooter className="gap-2">
                            <button ref={cancelRef} type="button" className="btn btn-sm capitalize" onClick={close} disabled={form.processing}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="btn btn-error btn-sm capitalize"
                                disabled={form.processing || !ready}
                                aria-busy={form.processing || undefined}
                            >
                                {form.processing ? copy.busy : copy.button}
                            </button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

interface PanelProps {
    caseId: string;
    reference: string | null;
    organisation: string | null;
    canWithdraw: boolean;
    canReject: boolean;
}

/**
 * TERMINAL-1: the case's ending actions, one ActionPanel on the Overview tab
 * (shown only when the server allows one). Withdraw while the case is in
 * intake or review; reject only once the PS has instructed it.
 */
export function EndCasePanel({ caseId, reference, organisation, canWithdraw, canReject }: PanelProps) {
    const [mode, setMode] = useState<Mode | null>(null);

    if (!canWithdraw && !canReject) {
        return null;
    }

    return (
        <ActionPanel
            title="End This Case"
            icon={CircleSlash}
            description={
                canReject
                    ? 'Reject the case on the instruction of the Permanent Secretary. The case closes for good.'
                    : 'Withdraw the case if the request will not go ahead. The case closes for good.'
            }
            actions={
                <>
                    {canWithdraw && (
                        <button type="button" className="btn btn-outline btn-error btn-sm capitalize" onClick={() => setMode('withdraw')}>
                            <Ban className="size-4" aria-hidden="true" />
                            Withdraw Case
                        </button>
                    )}
                    {canReject && (
                        <button type="button" className="btn btn-error btn-sm capitalize" onClick={() => setMode('reject')}>
                            <OctagonX className="size-4" aria-hidden="true" />
                            Reject Case
                        </button>
                    )}
                </>
            }
        >
            <EndCaseDialog mode={mode} caseId={caseId} reference={reference} organisation={organisation} onClose={() => setMode(null)} />
        </ActionPanel>
    );
}

/** TERMINAL-1 (staff): how the case ended, who and when, the internal reason and what the vendor was told. */
export function CaseEndedAlert({ ended }: { ended: CaseEnded }) {
    const rejected = ended.on_ps_instruction !== null;

    return (
        <div role="status" className={rejected ? 'alert alert-soft alert-error items-start' : 'alert alert-soft items-start'}>
            {rejected ? <OctagonX className="size-5" aria-hidden="true" /> : <Ban className="size-5" aria-hidden="true" />}
            <div className="grid gap-1 text-sm">
                <h2 className="font-semibold capitalize">{ended.label}</h2>
                <p>
                    {[ended.by ? `By ${ended.by}` : null, ended.at_label].filter((part) => part).join(', ')}
                    {ended.on_ps_instruction && ' · on the instruction of the Permanent Secretary'}
                </p>
                {ended.reason && <p className="whitespace-pre-line">Reason: {ended.reason}</p>}
                <p className="whitespace-pre-line">Message to the vendor: {ended.vendor_message}</p>
            </div>
        </div>
    );
}
