import { cn } from '@/lib/utils';
import { ArrowLeft, ArrowRight, Save, Trash2 } from 'lucide-react';

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
 * Delete draft on the left, then Back / save state / Save draft / Next
 * (secondary first, primary last). Form actions never go in the page header
 * (CLAUDE.md, UI).
 */
export function FormActions({ isFirst, isLast, dirty, processing, onBack, onSave, onNext, onDelete }: FormActionsProps) {
    return (
        <div className="bg-base-100 border-base-300 sticky bottom-0 z-10 -mx-4 border-t px-4 py-3 lg:-mx-6 lg:px-6">
            <div className="flex flex-wrap items-center gap-2 [&_.btn]:capitalize">
                {onDelete && (
                    <button type="button" className="btn btn-sm btn-outline btn-error" onClick={onDelete} disabled={processing}>
                        <Trash2 className="size-4" aria-hidden="true" />
                        Delete Draft
                    </button>
                )}
                <button type="button" className="btn btn-sm btn-ghost" onClick={onBack} disabled={isFirst || processing}>
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Back
                </button>
                <span className="text-base-content/70 ml-auto inline-flex items-center gap-2 text-xs" aria-live="polite">
                    {processing ? (
                        <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                    ) : (
                        <span aria-hidden="true" className={cn('status', dirty ? 'status-warning' : 'status-success')} />
                    )}
                    {processing ? 'Saving…' : dirty ? 'Unsaved changes' : 'All changes saved'}
                </span>
                <button type="button" className="btn btn-sm" onClick={onSave} disabled={!dirty || processing}>
                    <Save className="size-4" aria-hidden="true" />
                    Save Draft
                </button>
                <button type="button" className="btn btn-sm btn-primary" onClick={onNext} disabled={processing}>
                    {isLast ? 'Review and Submit' : 'Next'}
                    <ArrowRight className="size-4" aria-hidden="true" />
                </button>
            </div>
        </div>
    );
}
