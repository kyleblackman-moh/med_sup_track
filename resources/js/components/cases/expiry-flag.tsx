import { TONE_CLASSES } from '@/components/ui/status-pill';
import { cn } from '@/lib/utils';
import { type ExpiryFlag as ExpiryFlagData } from '@/types/cases';
import { TriangleAlert } from 'lucide-react';

interface ExpiryFlagProps {
    flag: ExpiryFlagData | null | undefined;
    /** The case header: the label and the detail. Rows: the label as a badge, the detail on hover and for screen readers. */
    variant?: 'badge' | 'alert';
    className?: string;
}

/**
 * Kyle, 2026-11-24: the stock expiry flag (ExpiryRisk::flag). The server sends
 * the label, the detail and the tone; nothing is drawn when it sends null. An
 * icon goes with the colour, so the flag never rests on colour alone.
 */
export function ExpiryFlag({ flag, variant = 'badge', className }: ExpiryFlagProps) {
    if (!flag) {
        return null;
    }

    if (variant === 'alert') {
        return (
            <div role="status" className={cn('alert alert-soft alert-error items-start py-2 text-sm', className)}>
                <TriangleAlert className="size-4 shrink-0" aria-hidden="true" />
                <div className="min-w-0">
                    <p className="font-semibold capitalize">{flag.label}</p>
                    <p>{flag.detail}</p>
                </div>
            </div>
        );
    }

    return (
        <span title={flag.detail} className={cn('badge badge-sm shrink-0 gap-1 whitespace-nowrap capitalize', TONE_CLASSES[flag.tone], className)}>
            <TriangleAlert className="size-3 shrink-0" aria-hidden="true" />
            {flag.label}
            <span className="sr-only">: {flag.detail}</span>
        </span>
    );
}
