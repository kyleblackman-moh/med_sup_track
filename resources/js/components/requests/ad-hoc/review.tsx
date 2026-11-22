import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type ChildSection, type NotSavedItem, type RequestSectionStatus, type SectionIssue } from '@/types/ad-hoc';
import { CircleAlert, Mailbox, Send } from 'lucide-react';
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
    /** OVERNIGHT-3 (A1): on the Review page, a link back to the field's section on the form. */
    href?: string;
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
    panelRef?: RefObject<HTMLElement | null>;
    /** The server's heading ("3 Things Need Fixing Before You Can Submit"). */
    heading: string | null;
    items: SummaryItem[];
    /** Scrolls to and focuses the issue's field (on the form). Items with an href link instead. */
    onJump?: (focusId: string) => void;
    /** OVERNIGHT-3 (A1): the Review page shows it on load, so there it is not an alert. */
    announce?: boolean;
}

/**
 * FORM-SAVE-1 (A5d): the ONE error summary, at the top of the form, when
 * Review and Submit is blocked. Announced (role="alert") and focused when it
 * opens; each line links to its field; it updates as the draft is saved and
 * disappears once nothing is left to fix. Autosave errors stay inline only.
 */
export function IssuesPanel({ panelRef, heading, items, onJump, announce = true }: IssuesPanelProps) {
    if (items.length === 0 || !heading) {
        return null;
    }

    return (
        <section
            ref={panelRef}
            tabIndex={-1}
            role={announce ? 'alert' : undefined}
            aria-labelledby="review-issues-heading"
            className="card bg-base-100 border-error border p-4 focus-visible:outline-2 focus-visible:outline-offset-2 md:p-5"
        >
            <h2 id="review-issues-heading" className="flex items-center gap-2 text-base font-semibold capitalize">
                <CircleAlert className="text-error size-5 shrink-0" aria-hidden="true" />
                {heading}
            </h2>
            <ul className="mt-3 [list-style-type:disc] space-y-1 pl-5 text-sm">
                {items.map((item) => (
                    <li key={item.key} className="break-words">
                        <a
                            href={item.href ?? `#${item.target}`}
                            className="link link-primary rounded-sm underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2"
                            onClick={(e) => {
                                if (item.href || !onJump) {
                                    return;
                                }
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
                    <DialogTitle className="capitalize">Submit this request?</DialogTitle>
                    <DialogDescription>Once submitted, the request can no longer be changed. You will get a reference number.</DialogDescription>
                </DialogHeader>
                <div className="alert bg-base-100 border-base-300 text-sm font-medium">
                    <Mailbox className="text-base-content/70 size-5 shrink-0" aria-hidden="true" />
                    <p>{hardcopyNotice}</p>
                </div>
                <DialogFooter>
                    <button type="button" className="btn btn-sm capitalize max-md:h-11" onClick={() => onOpenChange(false)} disabled={submitting}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary btn-sm capitalize max-md:h-11"
                        onClick={onConfirm}
                        disabled={submitting}
                        aria-busy={submitting || undefined}
                    >
                        {submitting ? (
                            <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                        ) : (
                            <Send className="size-4" aria-hidden="true" />
                        )}
                        {submitting ? 'Submitting...' : 'Submit Request'}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
