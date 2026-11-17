import { Button } from '@/components/ui/button';

interface FormActionsProps {
    isFirst: boolean;
    isLast: boolean;
    dirty: boolean;
    processing: boolean;
    onBack: () => void;
    onSave: () => void;
    onNext: () => void;
    /** Opens the shared delete confirmation; omitted when the draft cannot be deleted. */
    onDelete?: () => void;
}

/**
 * The form's footer row, pinned to the bottom of the screen on every tab:
 * Delete draft on the left, then Back / Save draft / Next. Form actions never
 * go in the page header (CLAUDE.md, UI).
 */
export function FormActions({ isFirst, isLast, dirty, processing, onBack, onSave, onNext, onDelete }: FormActionsProps) {
    return (
        <div className="bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky bottom-0 z-10 -mx-4 border-t px-4 py-3 backdrop-blur">
            <div className="flex flex-wrap items-center gap-2">
                {onDelete && (
                    <Button
                        type="button"
                        variant="outline"
                        className="text-destructive border-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={onDelete}
                        disabled={processing}
                    >
                        Delete draft
                    </Button>
                )}
                <Button type="button" variant="outline" onClick={onBack} disabled={isFirst || processing}>
                    Back
                </Button>
                <span className="text-muted-foreground ml-auto text-xs" aria-live="polite">
                    {processing ? 'Saving…' : dirty ? 'Unsaved changes' : 'All changes saved'}
                </span>
                <Button type="button" variant="secondary" onClick={onSave} disabled={!dirty || processing}>
                    Save draft
                </Button>
                <Button type="button" onClick={onNext} disabled={processing}>
                    {isLast ? 'Review and submit' : 'Next'}
                </Button>
            </div>
        </div>
    );
}
