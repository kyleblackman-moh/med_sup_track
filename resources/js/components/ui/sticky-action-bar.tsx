import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface StickyActionBarProps {
    /** The destructive action ("Delete draft") or status text. */
    left?: ReactNode;
    /** The primary action ("Review and submit"). */
    right?: ReactNode;
    className?: string;
}

/**
 * UI-4: the sticky action bar at the bottom of the L1 canvas (design.md
 * section 7): sticky to the bottom, a top border, white at 92% with a light
 * blur, padding that clears the device's safe area. The only place form
 * actions live. Hidden in print.
 */
export function StickyActionBar({ left, right, className }: StickyActionBarProps) {
    return (
        <div
            className={cn(
                'bg-card/92 sticky bottom-0 z-20 flex flex-wrap items-center justify-between gap-3 border-t px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-sm md:px-6 print:hidden',
                className,
            )}
        >
            <div className="flex min-w-0 flex-wrap items-center gap-2">{left}</div>
            <div className="ml-auto flex flex-wrap items-center gap-2">{right}</div>
        </div>
    );
}
