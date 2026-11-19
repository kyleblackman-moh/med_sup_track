import { RequestSummary } from '@/components/requests/request-summary';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type ChildSection, type NotSavedItem, type RequestSectionStatus, type SectionIssue } from '@/types/ad-hoc';
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
    // The type cards ("Choose a request type"). V2-5: the variation rows are a row section (below).
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

/** FORM-SAVE-1 (A5d): one line of the error summary: "Section name: message", linking to its field. */
export interface SummaryItem {
    key: string;
    sectionLabel: string;
    message: string;
    target: string;
}

/**
 * The summary's lines, in form order and without duplicates: the fields the
 * last draft save left out (when there are any: they block first), else the
 * server's readiness issues. Every word is the server's.
 */
export function summaryItems(sections: RequestSectionStatus[], notSaved: NotSavedItem[], state: FormState): SummaryItem[] {
    const lines =
        notSaved.length > 0
            ? notSaved.map((item) => ({
                  key: item.key,
                  sectionLabel: item.section_label,
                  message: item.message,
                  target: notSavedTarget(item.key, state),
              }))
            : sections.flatMap((section) =>
                  section.issues.map((issue) => ({
                      key: issue.key,
                      sectionLabel: section.label,
                      message: issue.message,
                      target: targetFor(issue, state),
                  })),
              );
    const seen = new Set<string>();

    return lines.filter((line) => {
        const id = `${line.sectionLabel}|${line.message}`;
        if (seen.has(id)) {
            return false;
        }
        seen.add(id);

        return true;
    });
}

/** The DOM id of a field the last save left out: a request field, or a row cell by its index in the payload. */
function notSavedTarget(key: string, state: FormState): string {
    const [first, second, third] = key.split('.');
    if (first === 'requisition') {
        return RADIO_FIELDS.includes(second) ? `${fieldId.requisition(second)}-0` : fieldId.requisition(second);
    }
    if ((CHILD_SECTIONS as string[]).includes(first)) {
        const section = first as ChildSection;
        const row = state.rows[section][Number(second)];

        return row && third ? fieldId.cell(section, row.key, third) : fieldId.table(section);
    }

    return first === 'previous_request_in_cycle' ? PREVIOUS_REQUEST_ID : '';
}

interface IssuesPanelProps {
    panelRef: RefObject<HTMLElement | null>;
    /** The server's heading ("3 Things Need Fixing Before You Can Submit"). */
    heading: string | null;
    items: SummaryItem[];
    /** Scrolls to and focuses the issue's field. */
    onJump: (focusId: string) => void;
}

/**
 * FORM-SAVE-1 (A5d): the ONE error summary, at the top of the form, when
 * Review and Submit is blocked. Announced (role="alert") and focused when it
 * opens; each line links to its field; it updates as the draft is saved and
 * disappears once nothing is left to fix. Autosave errors stay inline only.
 */
export function IssuesPanel({ panelRef, heading, items, onJump }: IssuesPanelProps) {
    if (items.length === 0 || !heading) {
        return null;
    }

    return (
        <section
            ref={panelRef}
            tabIndex={-1}
            role="alert"
            aria-labelledby="review-issues-heading"
            className="border-destructive bg-card focus-visible:ring-ring rounded-lg border p-6 focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <h2 id="review-issues-heading" className="text-base font-semibold">
                {heading}
            </h2>
            <ul className="mt-3 list-disc space-y-1 pl-5 text-sm">
                {items.map((item) => (
                    <li key={item.key} className="break-words">
                        <a
                            href={`#${item.target}`}
                            className="text-primary underline underline-offset-4"
                            onClick={(e) => {
                                e.preventDefault();
                                onJump(item.target);
                            }}
                        >
                            {item.sectionLabel}: {item.message}
                        </a>
                    </li>
                ))}
            </ul>
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
