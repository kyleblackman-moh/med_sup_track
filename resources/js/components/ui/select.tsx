import * as React from 'react';

import { cn } from '@/lib/utils';

/** daisyUI select on a native <select>; pass <option> children. */
const Select = React.forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(({ className, ...props }, ref) => (
    <select ref={ref} className={cn('select w-full', className)} {...props} />
));
Select.displayName = 'Select';

export { Select };
