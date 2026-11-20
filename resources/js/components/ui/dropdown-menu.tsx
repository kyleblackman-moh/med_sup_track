import * as React from 'react';

import { cn } from '@/lib/utils';

import { Slot } from './slot';

/**
 * daisyUI dropdown + menu. Open state lives in React (click to toggle) so a
 * focused trigger does not open the menu by itself. Closes on outside click,
 * Esc (focus back to the trigger) and item select; arrow keys move between items.
 */
interface DropdownContextValue {
    open: boolean;
    setOpen: (open: boolean) => void;
    triggerRef: React.RefObject<HTMLElement | null>;
    menuId: string;
}

const DropdownContext = React.createContext<DropdownContextValue | null>(null);

function useDropdown(): DropdownContextValue {
    const ctx = React.useContext(DropdownContext);
    if (!ctx) throw new Error('DropdownMenu parts must be used inside <DropdownMenu>.');
    return ctx;
}

function DropdownMenu({ children }: { children?: React.ReactNode }) {
    const [open, setOpen] = React.useState(false);
    const triggerRef = React.useRef<HTMLElement | null>(null);
    const rootRef = React.useRef<HTMLDivElement>(null);
    const menuId = React.useId();

    React.useEffect(() => {
        if (!open) return;
        const onPointer = (e: PointerEvent) => {
            if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('pointerdown', onPointer);
        return () => document.removeEventListener('pointerdown', onPointer);
    }, [open]);

    return (
        <DropdownContext.Provider value={{ open, setOpen, triggerRef, menuId }}>
            <div ref={rootRef} className="relative">
                {children}
            </div>
        </DropdownContext.Provider>
    );
}

const DropdownMenuTrigger = React.forwardRef<HTMLButtonElement, React.ButtonHTMLAttributes<HTMLButtonElement> & { asChild?: boolean }>(
    ({ asChild, onClick, ...props }, forwardedRef) => {
        const { open, setOpen, triggerRef, menuId } = useDropdown();
        const Comp = (asChild ? Slot : 'button') as React.ElementType;
        return (
            <Comp
                ref={(node: HTMLButtonElement | null) => {
                    triggerRef.current = node;
                    if (typeof forwardedRef === 'function') forwardedRef(node);
                    else if (forwardedRef) forwardedRef.current = node;
                }}
                type={asChild ? undefined : 'button'}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                data-state={open ? 'open' : 'closed'}
                onClick={(e: React.MouseEvent<HTMLButtonElement>) => {
                    onClick?.(e);
                    if (!e.defaultPrevented) setOpen(!open);
                }}
                {...props}
            />
        );
    },
);
DropdownMenuTrigger.displayName = 'DropdownMenuTrigger';

interface DropdownMenuContentProps extends React.HTMLAttributes<HTMLUListElement> {
    align?: 'start' | 'center' | 'end';
    side?: 'top' | 'right' | 'bottom' | 'left';
}

const POSITION = {
    bottom: 'top-full mt-1',
    top: 'bottom-full mb-1',
    left: 'right-full mr-1 bottom-0',
    right: 'left-full ml-1 bottom-0',
} as const;

const ALIGN = { start: 'left-0', center: 'left-1/2 -translate-x-1/2', end: 'right-0' } as const;

const DropdownMenuContent = React.forwardRef<HTMLUListElement, DropdownMenuContentProps>(
    ({ className, align = 'start', side = 'bottom', onKeyDown, ...props }, ref) => {
        const { open, setOpen, triggerRef, menuId } = useDropdown();
        const listRef = React.useRef<HTMLUListElement | null>(null);

        React.useEffect(() => {
            if (open) listRef.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
        }, [open]);

        if (!open) return null;

        const items = () => Array.from(listRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);

        return (
            <ul
                ref={(node) => {
                    listRef.current = node;
                    if (typeof ref === 'function') ref(node);
                    else if (ref) ref.current = node;
                }}
                id={menuId}
                role="menu"
                className={cn(
                    'menu bg-base-100 rounded-box border-base-300 absolute z-50 min-w-32 border p-1 shadow-sm',
                    POSITION[side],
                    side === 'top' || side === 'bottom' ? ALIGN[align] : null,
                    className,
                )}
                onKeyDown={(e) => {
                    onKeyDown?.(e);
                    const list = items();
                    const i = list.indexOf(document.activeElement as HTMLElement);
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        setOpen(false);
                        triggerRef.current?.focus();
                    } else if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        list[(i + 1) % list.length]?.focus();
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        list[(i - 1 + list.length) % list.length]?.focus();
                    } else if (e.key === 'Tab') {
                        setOpen(false);
                    }
                }}
                {...props}
            />
        );
    },
);
DropdownMenuContent.displayName = 'DropdownMenuContent';

const DropdownMenuItem = React.forwardRef<
    HTMLElement,
    React.HTMLAttributes<HTMLElement> & { asChild?: boolean; inset?: boolean; disabled?: boolean; variant?: 'default' | 'destructive' }
>(({ asChild, inset, disabled, variant, className, onClick, ...props }, ref) => {
    const { setOpen } = useDropdown();
    const Comp = (asChild ? Slot : 'button') as React.ElementType;
    return (
        <li role="none" className={cn(disabled && 'menu-disabled')}>
            <Comp
                ref={ref}
                role="menuitem"
                type={asChild ? undefined : 'button'}
                tabIndex={-1}
                aria-disabled={disabled || undefined}
                className={cn('flex items-center gap-2 [&_svg]:size-4 [&_svg]:shrink-0', inset && 'pl-8', variant === 'destructive' && 'text-error', className)}
                onClick={(e: React.MouseEvent<HTMLElement>) => {
                    if (disabled) return;
                    onClick?.(e);
                    setOpen(false);
                }}
                {...props}
            />
        </li>
    );
});
DropdownMenuItem.displayName = 'DropdownMenuItem';

const DropdownMenuLabel = ({ className, inset, ...props }: React.HTMLAttributes<HTMLLIElement> & { inset?: boolean }) => (
    <li role="none" className={cn('menu-title text-base-content px-2 py-1.5 text-sm font-semibold', inset && 'pl-8', className)} {...props} />
);
DropdownMenuLabel.displayName = 'DropdownMenuLabel';

const DropdownMenuSeparator = ({ className, ...props }: React.HTMLAttributes<HTMLLIElement>) => (
    <li role="separator" className={cn('bg-base-300 -mx-1 my-1 h-px p-0', className)} {...props} />
);
DropdownMenuSeparator.displayName = 'DropdownMenuSeparator';

/** Kept for call-site compatibility; a menu group is just its items. */
const DropdownMenuGroup = ({ children }: { children?: React.ReactNode }) => <>{children}</>;

export { DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger };
