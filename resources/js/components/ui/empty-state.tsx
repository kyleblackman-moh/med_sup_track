import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface EmptyStateProps {
    /** Sentence case, from the server where it can be (design.md section 4). */
    message: ReactNode;
    /** One clear next action, where one exists. */
    action?: ReactNode;
    /** Announce it (a list that just became empty after a search or filter). */
    live?: boolean;
    className?: string;
}

/** OVERNIGHT-3 (B1): the empty state (design.md section 7): centred 14px muted text, then one next action. */
export function EmptyState({ message, action, live = false, className }: EmptyStateProps) {
    return (
        <div className={cn('flex flex-col items-center gap-3 px-6 py-12 text-center', className)} role={live ? 'status' : undefined}>
            <p className="text-muted-foreground text-sm">{message}</p>
            {action}
        </div>
    );
}
