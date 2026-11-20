import * as React from 'react';

import { cn } from '@/lib/utils';

interface CheckboxProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type' | 'onChange'> {
    onCheckedChange?: (checked: boolean) => void;
    onChange?: React.ChangeEventHandler<HTMLInputElement>;
}

const Checkbox = React.forwardRef<HTMLInputElement, CheckboxProps>(({ className, onCheckedChange, onChange, ...props }, ref) => (
    <input
        ref={ref}
        type="checkbox"
        className={cn('checkbox checkbox-primary checkbox-sm peer', className)}
        onChange={(e) => {
            onChange?.(e);
            onCheckedChange?.(e.target.checked);
        }}
        {...props}
    />
));
Checkbox.displayName = 'Checkbox';

export { Checkbox };
