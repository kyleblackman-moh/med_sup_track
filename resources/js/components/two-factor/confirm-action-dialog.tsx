import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { useRef } from 'react';

interface ConfirmActionDialogProps {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    processing: boolean;
    title: string;
    /** What happens, in plain words. The dialog adds that it cannot be undone. */
    description: string;
    confirmLabel: string;
    icon: LucideIcon;
    /** Destructive actions (turning MFA off) use the error colour; others the primary. */
    destructive?: boolean;
}

/**
 * MFA: the confirmation for an irreversible security change (regenerating
 * recovery codes, turning two-factor authentication off). The shared native
 * <dialog class="modal"> (components/ui/dialog); Cancel has the focus when it
 * opens, and the buttons end the dialog (Cancel first, the action last).
 */
export function ConfirmActionDialog({
    open,
    onClose,
    onConfirm,
    processing,
    title,
    description,
    confirmLabel,
    icon: Icon,
    destructive = false,
}: ConfirmActionDialogProps) {
    const cancelRef = useRef<HTMLButtonElement>(null);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && !processing && onClose()}>
            <DialogContent
                onOpenAutoFocus={(e) => {
                    e.preventDefault();
                    cancelRef.current?.focus();
                }}
            >
                <DialogHeader>
                    <DialogTitle className="capitalize">{title}</DialogTitle>
                    <DialogDescription>{description} This can&apos;t be undone.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <button ref={cancelRef} type="button" className="btn btn-sm capitalize max-md:h-11" onClick={onClose} disabled={processing}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className={cn('btn btn-sm capitalize max-md:h-11', destructive ? 'btn-error' : 'btn-primary')}
                        onClick={onConfirm}
                        disabled={processing}
                        aria-busy={processing || undefined}
                    >
                        {processing ? (
                            <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                        ) : (
                            <Icon className="size-4" aria-hidden="true" />
                        )}
                        {confirmLabel}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
