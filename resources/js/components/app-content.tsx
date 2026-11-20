import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import * as React from 'react';

interface AppContentProps extends React.ComponentProps<'div'> {
    variant?: 'header' | 'sidebar';
}

export function AppContent({ variant = 'header', children, className, ...props }: AppContentProps) {
    if (variant === 'sidebar') {
        // min-w-0: a wide child must not push the content area wider than the screen.
        // V2-4 (design.md section 5): the page sits directly on the workspace
        // background, below the application header. The L1 canvas card, its 12px
        // gutter and the brand stripe are retired. Each page's PageContent gives the
        // 24px workspace padding (16px below md).
        return (
            <SidebarInset className={cn('bg-background min-h-0 min-w-0 outline-none', className)} {...props}>
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
