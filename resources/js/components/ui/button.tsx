import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

// UI-4 (design.md section 5): 36px high (44px below md), 8px radius. Primary: --primary with
// a --primary-strong border and the primary shadow; outline: white, --border, shadow-xs;
// secondary: --secondary. Motion (CSS only, reduced-motion safe): a 0.98 press in 100 ms.
const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-transparent text-sm font-medium ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 motion-safe:transition-[color,background-color,border-color,box-shadow,transform] motion-safe:duration-100 motion-safe:active:scale-[0.98] [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'border-primary-strong bg-primary text-primary-foreground shadow-primary hover:bg-primary-strong',
                destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive-text',
                outline: 'border-border bg-card shadow-xs hover:bg-subtle',
                secondary: 'bg-secondary text-secondary-foreground hover:border-secondary-foreground/20',
                ghost: 'hover:bg-muted',
                link: 'text-primary underline-offset-4 hover:text-primary-strong hover:underline',
            },
            size: {
                default: 'h-11 px-4 md:h-9',
                sm: 'h-11 px-3 md:h-8',
                lg: 'h-11 px-6',
                icon: 'size-11 md:size-9',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
    asChild?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(({ className, variant, size, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    return <Comp className={cn(buttonVariants({ variant, size, className }))} ref={ref} {...props} />;
});
Button.displayName = 'Button';

export { Button, buttonVariants };
