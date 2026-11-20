import * as React from 'react';

import { cn } from '@/lib/utils';

const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(({ className, type, ...props }, ref) => {
    return (
        <input
            type={type}
            className={cn(
                // OVERNIGHT-3 (B1, design.md section 5): --input-bg fill, the --input border (3:1), the focus ring, the invalid
                // --destructive border with a 3px --destructive-wash halo, and disabled on --muted.
                'flex h-10 w-full rounded-md border border-input bg-input-bg px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive-wash disabled:cursor-not-allowed disabled:border-border disabled:bg-muted disabled:text-muted-foreground md:text-sm',
                className,
            )}
            ref={ref}
            {...props}
        />
    );
});

Input.displayName = 'Input';

export { Input };
