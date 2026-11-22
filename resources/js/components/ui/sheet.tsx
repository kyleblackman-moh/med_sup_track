import * as React from 'react';

import { cn } from '@/lib/utils';

import { Dialog, DialogClose, DialogDescription, DialogTitle, DialogTrigger, ModalContent } from './dialog';

/** daisyUI modal pinned to a screen edge (modal-start / modal-end / modal-top / modal-bottom). */
const Sheet = Dialog;
const SheetTrigger = DialogTrigger;
const SheetClose = DialogClose;

const SIDES = {
    left: { modal: 'modal-start', box: 'h-full max-h-none w-3/4 max-w-sm rounded-none' },
    right: { modal: 'modal-end', box: 'h-full max-h-none w-3/4 max-w-sm rounded-none' },
    top: { modal: 'modal-top', box: 'w-full max-w-none rounded-none' },
    bottom: { modal: 'modal-bottom', box: 'w-full max-w-none rounded-none' },
} as const;

interface SheetContentProps extends React.HTMLAttributes<HTMLDivElement> {
    side?: keyof typeof SIDES;
}

const SheetContent = React.forwardRef<HTMLDivElement, SheetContentProps>(({ side = 'right', className, ...props }, ref) => (
    <ModalContent ref={ref} modalClassName={SIDES[side].modal} className={cn('content-start', SIDES[side].box, className)} {...props} />
));
SheetContent.displayName = 'SheetContent';

const SheetHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn('flex flex-col gap-2 text-center sm:text-left', className)} {...props} />
);
SheetHeader.displayName = 'SheetHeader';

const SheetFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', className)} {...props} />
);
SheetFooter.displayName = 'SheetFooter';

const SheetTitle = React.forwardRef<HTMLHeadingElement, React.HTMLAttributes<HTMLHeadingElement>>(({ className, ...props }, ref) => (
    <DialogTitle ref={ref} className={cn('text-base-content text-lg font-semibold', className)} {...props} />
));
SheetTitle.displayName = 'SheetTitle';

const SheetDescription = DialogDescription;

export { Sheet, SheetClose, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger };
