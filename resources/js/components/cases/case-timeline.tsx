import { type TimelineEntry } from '@/types/cases';

const TZ = 'America/Port_of_Spain';

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * The case's status history (staff only), in the order the server gives it.
 * Used by the History tab and the Send onward panel (slice 10e).
 */
export function CaseTimeline({ entries }: { entries: TimelineEntry[] }) {
    if (entries.length === 0) {
        return <p className="text-muted-foreground text-sm">No status changes recorded.</p>;
    }

    return (
        <ol className="space-y-3 border-l pl-4">
            {entries.map((t) => (
                <li key={t.id} className="text-sm">
                    <p className="font-medium">{t.from_label ? `${t.from_label} → ${t.to_label}` : t.to_label}</p>
                    <p className="text-muted-foreground">
                        {formatDateTime(t.at)} · {t.actor ?? 'System'}
                        {t.acting_for && ` (on behalf of ${t.acting_for})`}
                    </p>
                    {t.reason && <p className="mt-1 whitespace-pre-line">{t.reason}</p>}
                </li>
            ))}
        </ol>
    );
}
