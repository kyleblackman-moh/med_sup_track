import * as React from 'react';

import { cn } from '@/lib/utils';

const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(({ className, type, ...props }, ref) => {
    return <input type={type} className={cn(type === 'file' ? 'file-input w-full' : 'input w-full aria-invalid:input-error', className)} ref={ref} {...props} />;
});

Input.displayName = 'Input';

export { Input };
