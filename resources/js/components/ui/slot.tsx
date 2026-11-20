import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Minimal asChild support: renders the single child element with the parent's
 * props merged in (className concatenated, child handlers run first).
 */
export const Slot = React.forwardRef<HTMLElement, React.HTMLAttributes<HTMLElement> & { children?: React.ReactNode }>(
    ({ children, className, ...props }, ref) => {
        if (!React.isValidElement<Record<string, unknown>>(children)) return null;
        const child = children.props;
        const merged: Record<string, unknown> = { ...props, ...child, ref };
        for (const key of Object.keys(props)) {
            const ours = (props as Record<string, unknown>)[key];
            const theirs = child[key];
            if (key.startsWith('on') && typeof ours === 'function' && typeof theirs === 'function') {
                merged[key] = (...args: unknown[]) => {
                    (theirs as (...a: unknown[]) => void)(...args);
                    (ours as (...a: unknown[]) => void)(...args);
                };
            }
        }
        merged.className = cn(className, child.className as string | undefined);
        return React.cloneElement(children, merged);
    },
);
Slot.displayName = 'Slot';
