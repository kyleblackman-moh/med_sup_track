import { RequestSummary } from '@/components/requests/request-summary';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type ChildSection, type RequestSectionStatus, type SectionIssue } from '@/types/ad-hoc';
import { type RequestSummaryView } from '@/types/summary';
import { type RefObject } from 'react';
import { CHILD_SECTIONS, fieldId, type FormState } from './form-state';
import { PREVIOUS_REQUEST_ID } from './request-type-section';

/** Radio groups are focused on their first option. */
const RADIO_FIELDS = ['rfq_audience'];

/** The DOM id to focus for an issue: the field, the row's cell, or the table. */
export function targetFor(issue: SectionIssue, state: FormState): string {
    const [first, second] = issue.key.split('.');

    if (first === 'requisition') {
        return RADIO_FIELDS.includes(second) ? `${fieldId.requisition(second)}-0` : fieldId.requisition(second);
    }
    if (first === 'documents') {
        return fieldId.table('documents');
    }
    // V2-2: the variation's fields, and the type cards ("Choose a request type").
    if (first === 'variation') {
        return fieldId.variation(second);
    }
    if (first === 'request_type') {
        return 'request-type';
    }
    // V2-5: the previous-request question (its fieldset; the first radio is focused).
    if (first === 'previous_request_in_cycle') {
        return PREVIOUS_REQUEST_ID;
    }
    if ((CHILD_SECTIONS as string[]).includes(first)) {
        const section = first as ChildSection;
        const row = issue.row_id !== null ? state.rows[section].find((r) => r.id === issue.row_id) : undefined;

        return row ? fieldId.cell(section, row.key, issue.field.split('.')[1]) : fieldId.table(section);
    }

    return '';
}

interface IssuesPanelProps {
    panelRef: RefObject<HTMLElement | null>;
    sections: RequestSectionStatus[];
    state: FormState;
    /** Scrolls to and focuses the issue's field. */
    onJump: (focusId: string) => void;
}

/** What still needs doing, grouped by section, with jump links. Receives focus when shown. */
export function IssuesPanel({ panelRef, sections, state, onJump }: IssuesPanelProps) {
    const withIssues = sections.filter((section) => section.issues.length > 0);
    const count = withIssues.reduce((n, section) => n + section.issues.length, 0);

    return (
        <section
            ref={panelRef}
            tabIndex={-1}
            aria-labelledby="review-issues-heading"
            className="border-destructive bg-card focus-visible:ring-ring rounded-lg border p-6 focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <h2 id="review-issues-heading" className="text-base font-semibold">
                Before you can submit, {count === 1 ? '1 thing needs' : `${count} things need`} attention
            </h2>
            <div className="mt-4 space-y-4">
                {withIssues.map((section) => (
                    <div key={section.key}>
                        <h3 className="text-sm font-semibold">{section.label}</h3>
                        <ul className="mt-1 list-disc space-y-1 pl-5 text-sm">
                            {section.issues.map((issue) => {
                                const target = targetFor(issue, state);

                                return (
                                    <li key={issue.key}>
                                        <a
                                            href={`#${target}`}
                                            className="text-primary underline underline-offset-4"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                onJump(target);
                                            }}
                                        >
                                            {issue.message}
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </div>
        </section>
    );
}

interface SummaryPanelProps {
    panelRef: RefObject<HTMLElement | null>;
    /** V2-3: the server's RequestSummary of what is saved. */
    summary: RequestSummaryView;
    onSubmit: () => void;
}

/** A read-only summary of everything saved (the server's RequestSummary), then Submit. */
export function SummaryPanel({ panelRef, summary, onSubmit }: SummaryPanelProps) {
    return (
        <section
            ref={panelRef}
            tabIndex={-1}
            aria-labelledby="review-summary-heading"
            className="bg-card focus-visible:ring-ring space-y-6 rounded-lg border p-6 focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <div>
                <h2 id="review-summary-heading" className="text-base font-semibold">
                    Review Your Request
                </h2>
                <p className="text-muted-foreground text-sm">Everything below is saved. Check it, then submit.</p>
            </div>

            <RequestSummary summary={summary} idPrefix="review" />

            <div>
                <Button type="button" className="h-11 md:h-9" onClick={onSubmit}>
                    Submit Request
                </Button>
            </div>
        </section>
    );
}

interface SubmitDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    hardcopyNotice: string;
    submitting: boolean;
    onConfirm: () => void;
}

/** Confirm before submitting. Reuses the server's hardcopy wording (no deadline). */
export function SubmitDialog({ open, onOpenChange, hardcopyNotice, submitting, onConfirm }: SubmitDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Submit this request?</DialogTitle>
                    <DialogDescription>Once submitted, the request can no longer be changed. You will get a reference number.</DialogDescription>
                </DialogHeader>
                <p className="rounded-md border p-3 text-sm font-medium">{hardcopyNotice}</p>
                <DialogFooter>
                    <Button type="button" variant="outline" className="h-11 md:h-9" onClick={() => onOpenChange(false)} disabled={submitting}>
                        Cancel
                    </Button>
                    <Button type="button" className="h-11 md:h-9" onClick={onConfirm} disabled={submitting}>
                        {submitting ? 'Submitting...' : 'Submit Request'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
