import { type AuditRow } from '@/components/audit/types';
import { ShieldCheck, ShieldX } from 'lucide-react';

/** The server's outcome label and tone; the icon and the word carry it, the colour only supports. */
export function OutcomeBadge({ row }: { row: Pick<AuditRow, 'outcome_label' | 'outcome_tone'> }) {
    return row.outcome_tone === 'error' ? (
        <span className="badge badge-soft badge-error badge-sm whitespace-nowrap">
            <ShieldX className="size-3.5" aria-hidden="true" />
            {row.outcome_label}
        </span>
    ) : (
        <span className="badge badge-ghost badge-sm whitespace-nowrap">
            <ShieldCheck className="size-3.5" aria-hidden="true" />
            {row.outcome_label}
        </span>
    );
}
