import InputError from '@/components/input-error';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { Ban, TriangleAlert } from 'lucide-react';
import { type FormEvent, useRef, useState } from 'react';

const REASON_MIN = 10;
const REASON_MAX = 1000;

/**
 * FR-09: the vendor's "Withdraw Request" (a submitted request, before the
 * CMO's Office takes it in; the server checks again). The dialog says it
 * cannot be undone; buttons at the bottom, Withdraw Request last.
 */
export function WithdrawRequest({ caseId, reference }: { caseId: string; reference: string | null }) {
    const [open, setOpen] = useState(false);
    const cancelRef = useRef<HTMLButtonElement>(null);
    const form = useForm({ reason: '' });
    const ready = form.data.reason.trim().length >= REASON_MIN;

    const close = () => {
        if (form.processing) return;
        form.reset();
        form.clearErrors();
        setOpen(false);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!ready) return;
        form.post(route('requests.withdraw', caseId), { preserveScroll: true, onSuccess: () => close() });
    };

    return (
        <>
            <button type="button" className="btn btn-outline btn-error btn-sm capitalize" onClick={() => setOpen(true)}>
                <Ban className="size-4" aria-hidden="true" />
                Withdraw Request
            </button>

            <Dialog open={open} onOpenChange={(next) => !next && close()}>
                <DialogContent
                    onOpenAutoFocus={(e) => {
                        e.preventDefault();
                        cancelRef.current?.focus();
                    }}
                >
                    <form onSubmit={submit} className="grid gap-4" noValidate>
                        <DialogHeader>
                            <DialogTitle className="capitalize">Withdraw this request?</DialogTitle>
                            <DialogDescription>
                                <span className="font-mono font-medium">{reference ?? 'This request'}</span>
                            </DialogDescription>
                        </DialogHeader>

                        <div role="alert" className="alert alert-soft alert-error items-start text-sm">
                            <TriangleAlert className="size-5" aria-hidden="true" />
                            <p>
                                <span className="font-semibold">This cannot be undone.</span> The Ministry of Health stops work on the request. To go
                                ahead later, submit a new request.
                            </p>
                        </div>

                        <fieldset className="fieldset py-0">
                            <label className="label text-base-content text-sm font-medium capitalize" htmlFor="withdraw-reason">
                                Reason
                            </label>
                            <textarea
                                id="withdraw-reason"
                                aria-required="true"
                                rows={3}
                                maxLength={REASON_MAX}
                                className="textarea textarea-sm w-full"
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                                aria-invalid={form.errors.reason ? true : undefined}
                                aria-describedby="withdraw-reason-hint withdraw-reason-error"
                            />
                            <p id="withdraw-reason-hint" className="label text-helper">
                                Tell the Ministry why. {REASON_MIN} to {REASON_MAX} characters.
                            </p>
                            <InputError id="withdraw-reason-error" message={form.errors.reason} />
                        </fieldset>

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
                                {form.processing ? 'Withdrawing…' : 'Withdraw Request'}
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
