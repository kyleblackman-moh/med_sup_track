import { cn } from '@/lib/utils';
import { CircleAlert } from 'lucide-react';
import { HTMLAttributes } from 'react';

/**
 * The ONE field error (MASTER.md section 7; OVERNIGHT-3 B1): a 16px
 * CircleAlert and the message in the theme's error colour (text-error, 5.7:1
 * on base-100), never colour alone. Give it the id the control names in
 * aria-describedby. The vendor form's FieldError renders this component.
 */
export default function InputError({ message, className = '', ...props }: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p {...props} className={cn('text-error flex items-start gap-1.5 text-sm', className)}>
            <CircleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <span className="min-w-0 break-words">{message}</span>
        </p>
    ) : null;
}
