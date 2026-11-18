import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface StatTileProps {
    label: string;
    /** Already formatted by the server; shown as is. */
    value: string | number;
    /** One short line of neutral context in the footer strip. No targets or trends. */
    hint?: ReactNode;
    icon?: LucideIcon;
    /** Renders the tile as a link. */
    href?: string;
    /** Renders the tile as a toggle button (aria-pressed). */
    onClick?: () => void;
    /** The tile's filter is applied. */
    active?: boolean;
    className?: string;
}

/**
 * UI-4: a KPI stat tile (design.md section 7): an L3 white tile, a 30px tinted
 * icon square, a 13px/500 muted label, the value 28px/600 in tabular figures
 * and an optional footer strip. A clickable tile is a real link or button,
 * with an active state.
 */
export function StatTile({ label, value, hint, icon: Icon, href, onClick, active = false, className }: StatTileProps) {
    const interactive = href !== undefined || onClick !== undefined;
    const classes = cn(
        'bg-card flex min-w-0 flex-col overflow-hidden rounded-xl border text-left shadow-xs',
        interactive &&
            'hover:border-input focus-visible:ring-ring transition-[border-color,background-color] duration-150 outline-none focus-visible:ring-2 focus-visible:ring-offset-2 motion-reduce:transition-none',
        active && 'border-primary bg-selected ring-ring-wash ring-[3px]',
        className,
    );

    const body = (
        <>
            <span className="flex items-start gap-3 p-3.5">
                {Icon && (
                    <span className="bg-accent text-accent-foreground flex size-[30px] shrink-0 items-center justify-center rounded-lg" aria-hidden="true">
                        <Icon className="size-5" />
                    </span>
                )}
                <span className="grid min-w-0 gap-1">
                    <span className="text-muted-foreground text-[13px] font-medium">{label}</span>
                    <span className="text-[28px] leading-8 font-semibold tracking-[-0.02em] tabular-nums">{value}</span>
                </span>
            </span>
            {hint && (
                <span className="bg-strip text-muted-foreground mt-auto flex items-center gap-2 border-t px-3.5 py-2 text-xs">
                    <span className="bg-muted-foreground size-1.5 shrink-0 rounded-full" aria-hidden="true" />
                    {hint}
                </span>
            )}
        </>
    );

    if (href !== undefined) {
        return (
            <Link href={href} className={classes} aria-current={active ? 'true' : undefined}>
                {body}
            </Link>
        );
    }

    if (onClick !== undefined) {
        return (
            <button type="button" onClick={onClick} aria-pressed={active} className={classes}>
                {body}
            </button>
        );
    }

    return <div className={classes}>{body}</div>;
}
