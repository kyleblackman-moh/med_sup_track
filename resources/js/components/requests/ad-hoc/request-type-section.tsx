import { cn } from '@/lib/utils';
import { type RequestTypeOption } from '@/types/requests';
import { FieldError, fieldLabel, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { TypeCards } from './type-cards';

/** The question, in sentence case (V2-5 decision 3). */
export const PREVIOUS_REQUEST_QUESTION = 'Was a request previously made for this drug in this current tender cycle?';

/** Id of the question's fieldset (the jump-link target of its readiness issue). */
export const PREVIOUS_REQUEST_ID = 'f-previous_request_in_cycle';

interface RequestTypeSectionProps {
    api: FormApi;
    /** The type cards: present for a draft of the request form (RequestDetailResource type_options). */
    typeChoice?: {
        value: string;
        options: RequestTypeOption[];
        onChange: (code: string) => void;
        disabled: boolean;
        error?: string;
        showHiddenNote: boolean;
    };
    /** Shown once after the answer changes from Yes to No. */
    showVariationHiddenNote: boolean;
}

/**
 * V2-5 section 1 "Request Type": the five type cards (unchanged), then in one
 * row at lg (stacked below) the tender cycle start and end and the Yes/No
 * question. Every value is saved by the form's autosave; the server decides
 * what the answer shows (Details of Variation Requested only when Yes).
 */
export function RequestTypeSection({ api, typeChoice, showVariationHiddenNote }: RequestTypeSectionProps) {
    const field = (name: string) => ({
        id: fieldId.requisition(name),
        value: text(api.state.requisition[name]),
        onChange: (next: string) => api.setField(name, next),
        error: api.fieldError(name),
        optional: api.optional('request_type', name),
    });

    return (
        <div className="grid grid-cols-1 gap-6">
            {typeChoice && <TypeCards {...typeChoice} />}

            <div className="grid items-start gap-5 lg:grid-cols-3">
                <TextField {...field('tender_cycle_start')} label="Tender Cycle Start" type="date" />
                <TextField {...field('tender_cycle_end')} label="Tender Cycle End" type="date" hint="Not before the start." />
                <PreviousRequestQuestion
                    value={api.state.previousRequest}
                    onChange={api.setPreviousRequest}
                    error={api.tableError('previous_request_in_cycle')}
                    required={api.optional('request_type', 'previous_request_in_cycle') === false}
                    showHiddenNote={showVariationHiddenNote}
                />
            </div>
        </div>
    );
}

interface PreviousRequestQuestionProps {
    value: boolean | null;
    onChange: (value: boolean) => void;
    error?: string;
    required: boolean;
    showHiddenNote: boolean;
}

/**
 * A fieldset with the question as its legend (15px/600) and two native radios
 * shown as 40px pills. Nothing is preselected (null = not answered); the
 * chosen pill is solid --primary with white text; the focus ring shows on the
 * pill of the focused radio. Arrow keys move between the two (native radios).
 */
function PreviousRequestQuestion({ value, onChange, error, required, showHiddenNote }: PreviousRequestQuestionProps) {
    const errorId = `${PREVIOUS_REQUEST_ID}-error`;
    const noteId = `${PREVIOUS_REQUEST_ID}-note`;
    const legendId = `${PREVIOUS_REQUEST_ID}-legend`;
    const describedBy = [error ? errorId : null, showHiddenNote ? noteId : null].filter(Boolean).join(' ') || undefined;

    const pill = (answer: boolean, label: string) => (
        <label
            className={cn(
                'border-input bg-card text-foreground has-[:focus-visible]:ring-ring inline-flex h-10 min-w-20 cursor-pointer items-center justify-center rounded-full border px-5 text-sm font-semibold transition-colors duration-150 motion-reduce:transition-none',
                'hover:border-primary has-[:checked]:border-primary has-[:checked]:bg-primary has-[:checked]:text-primary-foreground',
                'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-offset-2',
            )}
        >
            <input
                type="radio"
                name="previous_request_in_cycle"
                value={answer ? 'yes' : 'no'}
                checked={value === answer}
                className="sr-only"
                onChange={() => onChange(answer)}
            />
            {label}
        </label>
    );

    return (
        <fieldset id={PREVIOUS_REQUEST_ID} className="grid gap-2" aria-describedby={describedBy} tabIndex={-1}>
            <legend id={legendId} className={cn('mb-2', fieldLabel)}>
                {PREVIOUS_REQUEST_QUESTION}
            </legend>
            <div role="radiogroup" aria-labelledby={legendId} aria-required={required ? true : undefined} className="flex flex-wrap gap-3">
                {pill(true, 'Yes')}
                {pill(false, 'No')}
            </div>
            <FieldError id={errorId} message={error} />
            {showHiddenNote && (
                <p id={noteId} role="status" className="text-muted-foreground text-[13px]">
                    Changing the answer to No hides Details of Variation Requested. What you entered there is kept until you submit.
                </p>
            )}
        </fieldset>
    );
}
