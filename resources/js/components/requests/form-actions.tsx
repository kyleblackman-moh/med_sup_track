import { Button } from '@/components/ui/button';

interface FormActionsProps {
    isFirst: boolean;
    isLast: boolean;
    dirty: boolean;
    processing: boolean;
    onBack: () => void;
    onSave: () => void;
    onNext: () => void;
}

/** Back / Save draft / Next, pinned to the bottom of the screen on every tab. */
export function FormActions({ isFirst, isLast, dirty, processing, onBack, onSave, onNext }: FormActionsProps) {
    return (
        <div className="bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky bottom-0 z-10 -mx-4 border-t px-4 py-3 backdrop-blur">
            <div className="flex flex-wrap items-center gap-2">
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
