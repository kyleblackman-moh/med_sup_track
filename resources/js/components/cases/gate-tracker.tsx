import { formatDay } from '@/components/cases/case-actions';
import { type GateState } from '@/types/cases';
import { Check } from 'lucide-react';

const TZ = 'America/Port_of_Spain';

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/**
 * The three-tick gate (slice 10b), read-only. Ticks, states, reviewer outcomes
 * and the missing list all come from the server; React decides nothing.
 * Plain text only: no deadlines, no lateness colours.
 */
export function GateTracker({ gate }: { gate: GateState | null }) {
    if (!gate) {
        return <p className="text-muted-foreground text-sm">Not yet sent for review.</p>;
    }

    return (
        <div className="space-y-3">
            <ul className="divide-y text-sm">
                {gate.rows.map((row) => (
                    <li key={row.key} className="grid gap-2 py-3 sm:grid-cols-[minmax(0,14rem)_1fr]">
                        <div className="flex items-start gap-2">
                            <span
                                className={
                                    row.done
                                        ? 'bg-primary text-primary-foreground mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full'
                                        : 'mt-0.5 size-5 shrink-0 rounded-full border'
                                }
                                aria-hidden
                            >
                                {row.done && <Check className="size-3.5" />}
                            </span>
                            <div>
                                <p className="font-medium">{row.label}</p>
                                <p className="text-muted-foreground">
                                    {row.done ? 'Done: ' : 'Not done: '}
                                    {row.state}
                                </p>
                            </div>
                        </div>

                        <div className="space-y-1">
                            {row.key === 'hardcopy' ? (
                                row.received_on ? (
                                    <p>
                                        Received on {formatDay(row.received_on)}
                                        {row.recorded_by && <span className="text-muted-foreground"> · recorded by {row.recorded_by}</span>}
                                        {row.recorded_at && <span className="text-muted-foreground"> on {formatDateTime(row.recorded_at)}</span>}
                                    </p>
                                ) : (
                                    <p className="text-muted-foreground">Not received</p>
                                )
                            ) : row.reviewers.length === 0 ? (
                                <p className="text-muted-foreground">No reviewer assigned</p>
                            ) : (
                                <ul className="space-y-1">
                                    {row.reviewers.map((r, i) => (
                                        <li key={`${row.key}-${i}`}>
                                            <span className="font-medium">{r.name ?? 'Unknown user'}</span>
                                            <span className="text-muted-foreground">
                                                {' · '}
                                                {r.outcome ?? 'No outcome yet'}
                                                {r.recorded_at && ` on ${formatDateTime(r.recorded_at)}`}
                                            </span>
                                            {r.reason && <p className="text-muted-foreground mt-0.5 whitespace-pre-line">Reason: {r.reason}</p>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </li>
                ))}
            </ul>

            {gate.missing.length > 0 && (
                <div className="text-sm">
                    <p className="font-medium">Before the case can be marked ready for file:</p>
                    <ul className="text-muted-foreground list-disc pl-5">
                        {gate.missing.map((m) => (
                            <li key={m}>{m}</li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
