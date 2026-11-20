import { X } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

import { Slot } from './slot';

/**
 * daisyUI modal on a native <dialog> (showModal): the browser traps focus,
 * handles Esc and returns focus on close. Same API as before: controlled
 * (open + onOpenChange) or uncontrolled with DialogTrigger/DialogClose.
 * Content is only mounted while open.
 */
interface DialogContextValue {
    open: boolean;
    setOpen: (open: boolean) => void;
    titleId: string;
    descriptionId: string;
}

const DialogContext = React.createContext<DialogContextValue | null>(null);

function useDialog(): DialogContextValue {
    const ctx = React.useContext(DialogContext);
    if (!ctx) throw new Error('Dialog parts must be used inside <Dialog>.');
    return ctx;
}

interface DialogProps {
    open?: boolean;
    defaultOpen?: boolean;
    onOpenChange?: (open: boolean) => void;
    children?: React.ReactNode;
}

function Dialog({ open: controlled, defaultOpen = false, onOpenChange, children }: DialogProps) {
    const [uncontrolled, setUncontrolled] = React.useState(defaultOpen);
    const open = controlled ?? uncontrolled;
    const setOpen = React.useCallback(
        (next: boolean) => {
            if (controlled === undefined) setUncontrolled(next);
            onOpenChange?.(next);
        },
        [controlled, onOpenChange],
    );
    const titleId = React.useId();
    const descriptionId = React.useId();
    return <DialogContext.Provider value={{ open, setOpen, titleId, descriptionId }}>{children}</DialogContext.Provider>;
}

const DialogTrigger = React.forwardRef<HTMLButtonElement, React.ButtonHTMLAttributes<HTMLButtonElement> & { asChild?: boolean }>(
    ({ asChild, onClick, ...props }, ref) => {
        const { setOpen } = useDialog();
        const Comp = (asChild ? Slot : 'button') as React.ElementType;
        return (
            <Comp
                ref={ref}
                type={asChild ? undefined : 'button'}
                onClick={(e: React.MouseEvent<HTMLButtonElement>) => {
                    onClick?.(e);
                    if (!e.defaultPrevented) setOpen(true);
                }}
                {...props}
            />
        );
    },
);
DialogTrigger.displayName = 'DialogTrigger';

const DialogClose = React.forwardRef<HTMLButtonElement, React.ButtonHTMLAttributes<HTMLButtonElement> & { asChild?: boolean }>(
    ({ asChild, onClick, ...props }, ref) => {
        const { setOpen } = useDialog();
        const Comp = (asChild ? Slot : 'button') as React.ElementType;
        return (
            <Comp
                ref={ref}
                type={asChild ? undefined : 'button'}
                onClick={(e: React.MouseEvent<HTMLButtonElement>) => {
                    onClick?.(e);
                    if (!e.defaultPrevented) setOpen(false);
                }}
                {...props}
            />
        );
    },
);
DialogClose.displayName = 'DialogClose';

interface ModalProps extends React.HTMLAttributes<HTMLDivElement> {
    /** Classes for the <dialog class="modal"> element (placement). */
    modalClassName?: string;
    showClose?: boolean;
    /** Runs right after the modal opens; call preventDefault() and focus your own element to override the native first-focusable focus. */
    onOpenAutoFocus?: (event: Event) => void;
}

/** Shared by Dialog and Sheet. */
const ModalContent = React.forwardRef<HTMLDivElement, ModalProps>(({ className, modalClassName, showClose = true, onOpenAutoFocus, children, ...props }, ref) => {
    const { open, setOpen, titleId, descriptionId } = useDialog();
    const dialogRef = React.useRef<HTMLDialogElement>(null);

    React.useEffect(() => {
        const el = dialogRef.current;
        if (open && el && !el.open) {
            el.showModal();
            onOpenAutoFocus?.(new Event('openautofocus', { cancelable: true }));
        }
    }, [open, onOpenAutoFocus]);

    if (!open) return null;

    return (
        <dialog
            ref={dialogRef}
            className={cn('modal', modalClassName)}
            aria-labelledby={titleId}
            aria-describedby={descriptionId}
            onCancel={(e) => {
                // Esc: let the owner decide (e.g. blocked while a form is processing).
                e.preventDefault();
                setOpen(false);
            }}
        >
            <div ref={ref} className={cn('modal-box grid gap-4', className)} {...props}>
                {children}
                {showClose && (
                    <button type="button" className="btn btn-sm btn-circle btn-ghost absolute top-3 right-3" onClick={() => setOpen(false)}>
                        <X className="size-4" aria-hidden="true" />
                        <span className="sr-only">Close</span>
                    </button>
                )}
            </div>
            <div className="modal-backdrop" aria-hidden="true" onClick={() => setOpen(false)} />
        </dialog>
    );
});
ModalContent.displayName = 'ModalContent';

const DialogContent = React.forwardRef<HTMLDivElement, ModalProps>(({ className, ...props }, ref) => (
    <ModalContent ref={ref} className={cn('max-w-lg', className)} {...props} />
));
DialogContent.displayName = 'DialogContent';

const DialogHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn('flex flex-col gap-1.5 text-center sm:text-left', className)} {...props} />
);
DialogHeader.displayName = 'DialogHeader';

const DialogFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn('modal-action mt-0 flex-col-reverse gap-2 sm:flex-row sm:justify-end', className)} {...props} />
);
DialogFooter.displayName = 'DialogFooter';

const DialogTitle = React.forwardRef<HTMLHeadingElement, React.HTMLAttributes<HTMLHeadingElement>>(({ className, ...props }, ref) => {
    const { titleId } = useDialog();
    return <h2 ref={ref} id={titleId} className={cn('text-lg leading-none font-semibold tracking-tight', className)} {...props} />;
});
DialogTitle.displayName = 'DialogTitle';

const DialogDescription = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLParagraphElement>>(({ className, ...props }, ref) => {
    const { descriptionId } = useDialog();
    return <p ref={ref} id={descriptionId} className={cn('text-base-content/70 text-sm', className)} {...props} />;
});
DialogDescription.displayName = 'DialogDescription';

export { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger, ModalContent, useDialog };
