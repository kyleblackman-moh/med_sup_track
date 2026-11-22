import { type TimelineEntry } from '@/types/cases';
import { CircleDot } from 'lucide-react';

const TZ = 'America/Port_of_Spain';

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * The case's status history (staff only), in the order the server gives it
 * (daisyUI timeline, vertical and compact). Used by the History tab and the
 * Send onward panel (slice 10e).
 */
export function CaseTimeline({ entries }: { entries: TimelineEntry[] }) {
    if (entries.length === 0) {
        return <p className="text-base-content/70 text-sm">No status changes recorded.</p>;
    }

    return (
        <ol className="timeline timeline-vertical timeline-compact timeline-snap-icon">
            {entries.map((t, i) => (
                <li key={t.id}>
                    {i > 0 && <hr className="bg-base-300" />}
                    <div className="timeline-middle">
                        <CircleDot className="text-primary size-4" aria-hidden="true" />
                    </div>
                    <div className="timeline-end mb-4 min-w-0 text-sm">
                        <p className="font-medium">{t.from_label ? `${t.from_label} → ${t.to_label}` : t.to_label}</p>
                        <p className="text-base-content/70">
                            <time dateTime={t.at ?? undefined} className="tabular-nums">
                                {formatDateTime(t.at)}
                            </time>{' '}
                            · {t.actor ?? 'System'}
                            {t.acting_for && ` (on behalf of ${t.acting_for})`}
                        </p>
                        {t.reason && <p className="mt-1 whitespace-pre-line">{t.reason}</p>}
                    </div>
                    {i < entries.length - 1 && <hr className="bg-base-300" />}
                </li>
            ))}
        </ol>
    );
}
