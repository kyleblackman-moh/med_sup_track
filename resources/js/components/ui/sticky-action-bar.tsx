import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface StickyActionBarProps {
    /** The destructive action ("Delete draft") or status text. */
    left?: ReactNode;
    /** The save and primary actions ("Save draft", "Review and submit"). */
    right?: ReactNode;
    className?: string;
}

/**
 * The sticky action bar (design.md section 7), restyled in V2-4: a white card
 * bar (16px radius, 1px border, --shadow-card, padding 16px 20px) at the end
 * of the page content, sticky to the bottom of the screen, the full content
 * width. It sits in the flow after the last section, so at the end of the page
 * it never covers the last field or message. Below sm the two groups wrap and
 * the buttons share the row. The only place form actions live. Hidden in print.
 */
export function StickyActionBar({ left, right, className }: StickyActionBarProps) {
    return (
        <div
            className={cn(
                'bg-card shadow-card sticky bottom-3 z-20 flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-5 py-4 print:hidden',
                className,
            )}
        >
            <div className="flex min-w-0 flex-wrap items-center gap-2 max-sm:w-full max-sm:*:flex-1">{left}</div>
            <div className="ml-auto flex flex-wrap items-center gap-2 max-sm:w-full max-sm:*:flex-1">{right}</div>
        </div>
    );
}
