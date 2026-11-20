import { type SaveStatus } from './use-autosave';

const TZ = 'America/Port_of_Spain';

/** "HH:MM" in Port of Spain time from the server's UTC save time. */
function clock(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
}

/** FORM-ERR-1 (D): why a save failed. Validation: the user can fix it. Anything else: try again. */
export const SAVE_FAILED_INVALID = 'Not saved. Fix the fields marked in red, then save again.';
export const SAVE_FAILED_ERROR = 'Not saved - try again';

/**
 * The autosave state: "Saving...", "Saved HH:MM", or a failed save as a
 * button that saves again, saying why it failed: the fields marked in red
 * (validation), or "try again" (a network or server error).
 */
export function SaveChip({
    status,
    savedAt,
    partialText,
    onRetry,
}: {
    status: SaveStatus;
    savedAt: string | null;
    /** FORM-SAVE-1 (A2): the server's status line for a partial save. */
    partialText?: string | null;
    onRetry: () => void;
}) {
    const failed = status === 'invalid' ? SAVE_FAILED_INVALID : status === 'error' ? SAVE_FAILED_ERROR : null;

    return (
        <div role="status" aria-live="polite" className="text-sm">
            {status === 'saving' && <span className="text-muted-foreground">Saving...</span>}
            {status === 'saved' && savedAt && <span className="text-muted-foreground">Saved {clock(savedAt)}</span>}
            {status === 'partial' && partialText && <span className="text-destructive-text">{partialText}</span>}
            {failed && (
                <button
                    type="button"
                    onClick={onRetry}
                    className="text-destructive-text focus-visible:ring-ring min-h-11 rounded-md text-left underline underline-offset-4 focus-visible:ring-2 focus-visible:outline-hidden md:min-h-9"
                >
                    {failed}
                </button>
            )}
        </div>
    );
}
