import { type SaveStatus } from './use-autosave';

const TZ = 'America/Port_of_Spain';

/** "HH:MM" in Port of Spain time from the server's UTC save time. */
function clock(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
}

/** The autosave state: "Saving...", "Saved HH:MM" or "Not saved - try again" (a button). */
export function SaveChip({ status, savedAt, onRetry }: { status: SaveStatus; savedAt: string | null; onRetry: () => void }) {
    return (
        <div role="status" aria-live="polite" className="text-sm">
            {status === 'saving' && <span className="text-muted-foreground">Saving...</span>}
            {status === 'saved' && savedAt && <span className="text-muted-foreground">Saved {clock(savedAt)}</span>}
            {status === 'error' && (
                <button
                    type="button"
                    onClick={onRetry}
                    className="text-destructive focus-visible:ring-ring min-h-11 rounded-md underline underline-offset-4 focus-visible:ring-2 focus-visible:outline-hidden md:min-h-9"
                >
                    Not saved - try again
                </button>
            )}
        </div>
    );
}
