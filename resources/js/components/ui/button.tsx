import * as React from 'react';

import { cn } from '@/lib/utils';

import { Slot } from './slot';

// daisyUI btn. Heights keep design.md section 5 touch targets: 44px below md, 36px from md.
const VARIANTS = {
    default: 'btn-primary',
    destructive: 'btn-error',
    outline: 'btn-outline border-base-300 bg-base-100 text-base-content hover:bg-base-200',
    secondary: 'btn-secondary',
    ghost: 'btn-ghost',
    link: 'btn-link',
} as const;

const SIZES = {
    default: 'h-11 md:h-9',
    sm: 'btn-sm h-11 md:h-8',
    lg: 'btn-lg h-11',
    icon: 'btn-square size-11 md:size-9',
} as const;

type ButtonVariant = keyof typeof VARIANTS;
type ButtonSize = keyof typeof SIZES;

function buttonVariants({ variant, size, className }: { variant?: ButtonVariant | null; size?: ButtonSize | null; className?: string } = {}) {
    return cn('btn [&_svg]:size-4 [&_svg]:shrink-0', VARIANTS[variant ?? 'default'], SIZES[size ?? 'default'], className);
}

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant | null;
    size?: ButtonSize | null;
    asChild?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(({ className, variant, size, asChild = false, ...props }, ref) => {
    const Comp = (asChild ? Slot : 'button') as React.ElementType;
    return <Comp className={buttonVariants({ variant, size, className })} ref={ref} {...props} />;
});
Button.displayName = 'Button';

export { Button, buttonVariants };
