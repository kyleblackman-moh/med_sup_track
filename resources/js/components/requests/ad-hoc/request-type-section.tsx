import { todayInPortOfSpain } from '@/components/ui/date-picker';
import { FIELD_WIDTH, type FieldWidth } from '@/components/ui/field-width';
import { RadioGroup } from '@/components/ui/radio-group';
import { type RequestTypeOption } from '@/types/requests';
import { FieldError, FieldGrid, FieldHint, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { TypeCards } from './type-cards';

/** The question, in sentence case (V2-5 decision 3). */
export const PREVIOUS_REQUEST_QUESTION = 'Was a request previously made for this drug in this current tender cycle?';

/** Id of the question's fieldset (the jump-link target of its readiness issue). */
export const PREVIOUS_REQUEST_ID = 'f-previous_request_in_cycle';

const ANSWERS = [
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
];

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
 * V2-5 section 1 "Request Type": the five type cards (unchanged), then the
 * tender cycle start and end and the Yes/No question, in one row once the
 * card is 56rem wide (FORM-COMPACT-1, FieldGrid), stacked below. Every value is saved by the form's autosave; the server decides
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
    const today = todayInPortOfSpain();

    return (
        <div className="grid grid-cols-1 gap-4">
            {typeChoice && <TypeCards {...typeChoice} />}

            {/* FORM-COMPACT-1: the two dates (xs) and the question (lg) share one row from a 56rem card. */}
            <FieldGrid>
                {/* Kyle, 2026-11-24: the cycle must include today (the server checks it too). */}
                <TextField {...field('tender_cycle_start')} width="xs" label="Tender Cycle Start" type="date" max={today} hint="Today or earlier." />
                <TextField {...field('tender_cycle_end')} width="xs" label="Tender Cycle End" type="date" min={today} hint="Today or later." />
                <PreviousRequestQuestion
                    width="lg"
                    value={api.state.previousRequest}
                    onChange={api.setPreviousRequest}
                    error={api.tableError('previous_request_in_cycle')}
                    required={api.optional('request_type', 'previous_request_in_cycle') === false}
                    showHiddenNote={showVariationHiddenNote}
                />
            </FieldGrid>
        </div>
    );
}

interface PreviousRequestQuestionProps {
    value: boolean | null;
    onChange: (value: boolean) => void;
    error?: string;
    required: boolean;
    showHiddenNote: boolean;
    width: FieldWidth;
}

/**
 * The shared RadioGroup (RADIO-1): the question as its legend (sentence
 * case) and the two answers as stacked rows. Nothing is preselected (null =
 * not answered). The fieldset takes focus from the readiness jump link.
 */
function PreviousRequestQuestion({ value, onChange, error, required, showHiddenNote, width }: PreviousRequestQuestionProps) {
    const errorId = `${PREVIOUS_REQUEST_ID}-error`;
    const noteId = `${PREVIOUS_REQUEST_ID}-note`;
    const legendId = `${PREVIOUS_REQUEST_ID}-legend`;
    const describedBy = [error ? errorId : null, showHiddenNote ? noteId : null].filter(Boolean).join(' ') || undefined;
    // null matches neither answer, so nothing is checked until the vendor answers.
    const answer = value === null ? null : value ? 'yes' : 'no';

    return (
        <RadioGroup
            id={PREVIOUS_REQUEST_ID}
            name="previous_request_in_cycle"
            legend={PREVIOUS_REQUEST_QUESTION}
            legendId={legendId}
            legendCase="sentence"
            options={ANSWERS}
            value={answer}
            onChange={(next) => onChange(next === 'yes')}
            describedBy={describedBy}
            required={required}
            tabIndex={-1}
            className={FIELD_WIDTH[width]}
        >
            <FieldError id={errorId} message={error} />
            {showHiddenNote && (
                <div role="status">
                    <FieldHint hintId={noteId}>
                        Changing the answer to No hides Details of Variation Requested. What you entered there is kept until you submit.
                    </FieldHint>
                </div>
            )}
        </RadioGroup>
    );
}
