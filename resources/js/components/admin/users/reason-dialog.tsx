import InputError from '@/components/input-error';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { KeyRound, TriangleAlert } from 'lucide-react';
import { type FormEvent, type ReactNode, useRef } from 'react';

export interface ReasonDialogProps {
    open: boolean;
    onClose: () => void;
    title: string;
    description: ReactNode;
    /** The route name and method the reason is sent to; `extra` is merged into the payload (for example the new role). */
    action: { route: string; userId: number; method: 'post' | 'put'; extra?: Record<string, string> };
    confirmLabel: string;
    busyLabel: string;
    /** The server's limit (UserController::REASON_MAX). */
    reasonMax: number;
    reasonRequired: boolean;
    /** Danger styling for the confirm button and the warning alert. */
    destructive?: boolean;
    /** When set, a warning alert leads the dialog (for example "This cannot be undone."). */
    warning?: ReactNode;
    children?: ReactNode;
}

/**
 * IT-ADMIN: the one confirmation for an account change (role, disable,
 * enable, MFA reset). It names what will happen, takes the reason the server
 * asks for (kept on the account's history, never in the audit log), and
 * ends with Cancel, then the confirm button. Cancel has the focus when it
 * opens. If the password was not confirmed in the last 15 minutes, the
 * server sends the user to Confirm Password first; nothing changes until
 * they confirm and repeat the action.
 */
export function ReasonDialog({
    open,
    onClose,
    title,
    description,
    action,
    confirmLabel,
    busyLabel,
    reasonMax,
    reasonRequired,
    destructive = false,
    warning,
    children,
}: ReasonDialogProps) {
    const cancelRef = useRef<HTMLButtonElement>(null);
    const form = useForm<{ reason: string }>({ reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const ready = !reasonRequired || form.data.reason.trim() !== '';

    const close = () => {
        if (form.processing) return;
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!ready) return;
        form.transform((data) => ({ ...data, ...(action.extra ?? {}) }));
        form.submit(action.method, route(action.route, action.userId), { preserveScroll: true, onSuccess: () => close() });
    };

    const otherError = errors.role ?? errors.account ?? errors.user;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent
                onOpenAutoFocus={(e) => {
                    e.preventDefault();
                    cancelRef.current?.focus();
                }}
            >
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle className="capitalize">{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>

                    {warning && (
                        <div
                            role="alert"
                            className={
                                destructive
                                    ? 'alert alert-soft alert-error items-start text-sm'
                                    : 'alert alert-soft alert-warning items-start text-sm'
                            }
                        >
                            <TriangleAlert className="size-5" aria-hidden="true" />
                            <div>{warning}</div>
                        </div>
                    )}

                    {children}

                    <fieldset className="fieldset py-0">
                        <label className="label text-base-content text-sm font-medium capitalize" htmlFor="account-reason">
                            {reasonRequired ? 'Reason' : 'Reason (optional)'}
                        </label>
                        <textarea
                            id="account-reason"
                            rows={3}
                            maxLength={reasonMax}
                            aria-required={reasonRequired || undefined}
                            className="textarea textarea-sm w-full"
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            aria-invalid={errors.reason ? true : undefined}
                            aria-describedby="account-reason-hint account-reason-error"
                        />
                        <p id="account-reason-hint" className="label text-helper flex flex-wrap justify-between gap-2 whitespace-normal">
                            <span>Kept on the account&apos;s history for administrators. The user does not see it.</span>
                            <span className="tabular-nums">
                                {form.data.reason.length}/{reasonMax}
                            </span>
                        </p>
                        <InputError id="account-reason-error" message={errors.reason} />
                    </fieldset>

                    <InputError message={otherError} role="alert" />

                    <p className="text-base-content/70 text-helper flex items-start gap-2">
                        <KeyRound className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        You may be asked to confirm your password first.
                    </p>

                    <DialogFooter className="gap-2">
                        <button ref={cancelRef} type="button" className="btn btn-sm capitalize" onClick={close} disabled={form.processing}>
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className={destructive ? 'btn btn-error btn-sm capitalize' : 'btn btn-primary btn-sm capitalize'}
                            disabled={form.processing || !ready}
                            aria-busy={form.processing || undefined}
                        >
                            {form.processing ? busyLabel : confirmLabel}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
