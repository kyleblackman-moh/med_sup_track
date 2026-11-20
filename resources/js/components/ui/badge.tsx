import * as React from 'react';

import { cn } from '@/lib/utils';

const VARIANTS = {
    default: 'badge-primary',
    secondary: 'badge-secondary',
    destructive: 'badge-error',
    outline: 'badge-outline',
} as const;

type BadgeVariant = keyof typeof VARIANTS;

function badgeVariants({ variant }: { variant?: BadgeVariant | null } = {}) {
    return cn('badge badge-sm font-semibold', VARIANTS[variant ?? 'default']);
}

export interface BadgeProps extends React.HTMLAttributes<HTMLDivElement> {
    variant?: BadgeVariant | null;
}

function Badge({ className, variant, ...props }: BadgeProps) {
    return <div className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
