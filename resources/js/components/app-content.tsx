import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import * as React from 'react';

interface AppContentProps extends React.ComponentProps<'div'> {
    variant?: 'header' | 'sidebar';
}

export function AppContent({ variant = 'header', children, className, ...props }: AppContentProps) {
    if (variant === 'sidebar') {
        // min-w-0: a wide child must not push the content area wider than the screen.
        // UI-4: the L1 canvas (design.md section 5): white, 1px border, 16px radius,
        // shadow-xs, clipped, 12px from the L0 page edge. Full width and plain in print.
        return (
            <SidebarInset
                className={cn(
                    'bg-card min-w-0 overflow-clip outline-none',
                    'md:peer-data-[variant=inset]:m-3 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-2xl md:peer-data-[variant=inset]:border md:peer-data-[variant=inset]:shadow-xs',
                    'md:peer-data-[variant=inset]:min-h-[calc(100svh-1.5rem)] md:peer-data-[variant=inset]:peer-data-[state=collapsed]:ml-3',
                    'print:m-0! print:min-h-0 print:rounded-none! print:border-0! print:shadow-none!',
                    className,
                )}
                {...props}
            >
                {children}
            </SidebarInset>
        );
    }

    return (
        <main className={cn('mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl', className)} {...props}>
            {children}
        </main>
    );
}
