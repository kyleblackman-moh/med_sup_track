import { cn } from '@/lib/utils';
import * as React from 'react';

/**
 * OVERNIGHT-3 (B1): the one text area style (was the vendor form's own):
 * --input-bg fill, the --input border (3:1), the focus ring, and the invalid
 * --destructive border with a 3px --destructive-wash halo.
 */
export const textareaClass =
    'border-input bg-input-bg aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive-wash placeholder:text-muted-foreground focus-visible:ring-ring ring-offset-background flex min-h-20 w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:bg-muted disabled:cursor-not-allowed md:text-sm';

const Textarea = React.forwardRef<HTMLTextAreaElement, React.ComponentProps<'textarea'>>(({ className, ...props }, ref) => (
    <textarea ref={ref} className={cn(textareaClass, className)} {...props} />
));

Textarea.displayName = 'Textarea';

export { Textarea };
