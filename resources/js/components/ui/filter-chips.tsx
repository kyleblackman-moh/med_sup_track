import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ComponentProps } from 'react';

/** One chip, built by the server: its label, count and link (the filter with the current search). */
export interface FilterChip {
    key: string;
    label: string;
    count: number;
    href: string;
    active: boolean;
}

interface FilterChipsProps {
    /** Names the navigation ("Filter the work queue"). */
    label: string;
    chips: FilterChip[];
    /** Extra Link props (for example `only` for a partial reload). */
    linkProps?: Partial<ComponentProps<typeof Link>>;
    className?: string;
}

/**
 * OVERNIGHT-3 (B1): filter chips (design.md section 7): 30px pills with a
 * count, each a real link. Active is --primary with white text and
 * aria-current; inactive is white with --border and a --muted count.
 */
export function FilterChips({ label, chips, linkProps, className }: FilterChipsProps) {
    return (
        <nav aria-label={label} className={className}>
            <ul className="flex flex-wrap gap-2">
                {chips.map((filter) => (
                    <li key={filter.key}>
                        <Link
                            href={filter.href}
                            preserveState
                            preserveScroll
                            {...linkProps}
                            aria-current={filter.active ? 'true' : undefined}
                            className={cn(
                                'focus-visible:ring-ring text-helper inline-flex h-[30px] items-center gap-1.5 rounded-full border px-3 font-medium outline-none focus-visible:ring-2 focus-visible:ring-offset-2 max-md:h-11',
                                filter.active ? 'border-primary bg-primary text-primary-foreground' : 'bg-card border-border hover:bg-muted',
                            )}
                        >
                            {filter.label}
                            <span
                                className={cn(
                                    'rounded-full px-1.5 text-xs tabular-nums',
                                    filter.active ? 'bg-primary-foreground/20' : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {filter.count}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
