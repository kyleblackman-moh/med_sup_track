import { ActionPanel } from '@/components/cases/action-panel';
import { HardcopyRecordForm } from '@/components/cases/case-actions';
import { ForwardForReview } from '@/components/cases/forward-for-review';
import { LegalDecisionForm, PrepareFileForm, SendToLegal } from '@/components/cases/legal-actions';
import { IssueLetterForm } from '@/components/cases/letter-actions';
import { PsDecisionForm } from '@/components/cases/ps-actions';
import { ReviewOutcomeForm } from '@/components/cases/review-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    type EligibleReviewers,
    type LegalForm,
    type NextStepAction,
    type NextStepItem,
    type NextStepView,
    type PsForm,
    type ReviewerOption,
    type ReviewForm,
    type RouteDestination,
} from '@/types/cases';
import { Link, useForm } from '@inertiajs/react';
import { Circle, CircleCheck, FileDown, Info, MessagesSquare, QrCode, Signpost } from 'lucide-react';
import { useState } from 'react';

/** Actions that post with no input: the card's own confirmation dialog, then the route. */
const SIMPLE_ROUTES: Record<string, string> = {
    intake: 'cases.intake',
    mark_ready_for_file: 'cases.ready',
    close_case: 'cases.close',
};

/** Actions whose form opens inside the card (each has its own confirmation step). */
const INLINE = ['prepare_file', 'legal_decision', 'ps_decision', 'issue_letter', 'record_technical', 'record_funding'];

interface Props {
    caseId: string;
    /** HARDCOPY-1: shown in the hardcopy confirmation (reference and vendor). */
    reference: string | null;
    organisation: string | null;
    next: NextStepView;
    reviewers: EligibleReviewers | null;
    fileNoDefault: string | null;
    legalOfficers: ReviewerOption[] | null;
    legalForm: LegalForm | null;
    psForm: PsForm | null;
    reviewForms: ReviewForm[];
    letterToday: string | null;
    /** Opens the Send Onward panel at the chosen destination; null when the user may not route the case. */
    onRoute: ((destination: RouteDestination['value']) => void) | null;
    /** OVERNIGHT-2 (D4): opens the Queries tab, where the forward or answer is done. */
    onOpenQueries: () => void;
}

/** "Received on ...", "Favourable", plus who holds the item. All text from the server. */
function ChecklistRow({ item }: { item: NextStepItem }) {
    const Icon = item.done ? CircleCheck : Circle;

    return (
        <li className="list-row items-start gap-3 px-3 py-2.5">
            <Icon aria-hidden="true" className={item.done ? 'text-primary mt-0.5 size-4 shrink-0' : 'text-base-content/50 mt-0.5 size-4 shrink-0'} />
            <div className="list-col-grow min-w-0 space-y-0.5 text-sm">
                <p>
                    <span className="sr-only">{item.done ? 'Done: ' : 'Not done: '}</span>
                    <span className="font-medium">{item.label}</span>
                    <span className="text-base-content/70"> · {item.state}</span>
                </p>
                <p className="text-base-content/70">
                    Held by {item.holder}
                    {item.people.length > 0 && `: ${item.people.join(', ')}`}
                </p>
            </div>
        </li>
    );
}

/** The card's own confirmation for an action with no input. */
function ConfirmAction({
    caseId,
    action,
    open,
    onOpenChange,
}: {
    caseId: string;
    action: NextStepAction;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({});
    const errors = Object.values(form.errors as Record<string, string>);

    const submit = () => form.post(route(SIMPLE_ROUTES[action.key], caseId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });

    return (
        <Dialog open={open} onOpenChange={(next) => !form.processing && onOpenChange(next)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{action.confirm.title}</DialogTitle>
                    <DialogDescription>{action.confirm.body}</DialogDescription>
                </DialogHeader>
                {errors.map((message) => (
                    <InputError key={message} message={message} />
                ))}
                <DialogFooter className="gap-2">
                    <Button type="button" variant="ghost" onClick={() => onOpenChange(false)} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Saving…' : action.confirm.button}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * NEXT-STEP-1: where the file is, who holds it, what happens next, what is
 * missing, and the actions this user may take now, at the top of the staff
 * case page's action column on every tab (ActionPanel, buttons in its footer). Every word comes from the server's NextStep DTO;
 * this only renders it. A user without the permission sees the holder and the
 * instruction but no button. Disabled actions stay visible with their reason.
 */
export function NextStepCard({
    caseId,
    reference,
    organisation,
    next,
    reviewers,
    fileNoDefault,
    legalOfficers,
    legalForm,
    psForm,
    reviewForms,
    letterToday,
    onRoute,
    onOpenQueries,
}: Props) {
    const [dialog, setDialog] = useState<string | null>(null);
    const [expanded, setExpanded] = useState<string | null>(null);

    // Buttons at the end of the panel: secondary first, the primary step last.
    const ordered = [...next.actions.filter((a) => !a.primary), ...next.actions.filter((a) => a.primary)];
    const expandedAction = next.actions.find((a) => a.key === expanded) ?? null;
    const reasons = ordered.filter((a) => a.disabled_reason);
    const hardcopyForms = next.checklist.filter((item) => item.hardcopy_form);
    const queryActions = next.queries.filter((q) => q.action);

    /** Inline forms (they carry their own confirmation step); everything else opens a dialog or the panel. */
    const inlineForm = (action: NextStepAction) => {
        switch (action.key) {
            case 'prepare_file':
                return (
                    fileNoDefault !== null && <PrepareFileForm caseId={caseId} defaultFileNo={fileNoDefault} submitLabel={action.confirm.button} />
                );
            case 'legal_decision':
                return legalForm && <LegalDecisionForm caseId={caseId} form={legalForm} />;
            case 'ps_decision':
                return psForm && <PsDecisionForm caseId={caseId} form={psForm} />;
            case 'issue_letter':
                return letterToday && <IssueLetterForm caseId={caseId} today={letterToday} confirm={action.confirm} />;
            case 'record_technical':
            case 'record_funding': {
                const form = reviewForms.find((f) => `record_${f.track}` === action.key);
                return form && <ReviewOutcomeForm caseId={caseId} form={form} />;
            }
            default:
                return null;
        }
    };

    const open = (action: NextStepAction) => {
        if (action.route !== null) {
            onRoute?.(action.route);
        } else if (INLINE.includes(action.key)) {
            setExpanded(expanded === action.key ? null : action.key);
        } else {
            setDialog(action.key);
        }
    };

    return (
        <>
            <ActionPanel
                title="Next Step"
                icon={Signpost}
                description={
                    <>
                        <span>Where This File Is: </span>
                        <span className="text-base-content font-medium">{next.stage}</span>
                        {next.holder && (
                            <>
                                <span> · Held by </span>
                                <span className="text-base-content font-medium">{next.holder}</span>
                                {next.assigned.length > 0 && <span className="text-base-content"> ({next.assigned.join(', ')})</span>}
                            </>
                        )}
                        {next.since_label && (
                            <>
                                <span> · Since </span>
                                <time dateTime={next.since ?? undefined} className="text-base-content tabular-nums">
                                    {next.since_label}
                                </time>
                            </>
                        )}
                    </>
                }
                actions={
                    <>
                        {/* Backlog, 2026-11-25: the request letter, for anyone who can see the case (the route checks it). */}
                        <a href={route('cases.letter', caseId)} className="btn btn-outline btn-sm" download>
                            <FileDown className="size-4" aria-hidden="true" />
                            Export PDF
                        </a>
                        {/* FR-03: the printable hardcopy cover sheet (QR of the reference); every case past draft has a reference. */}
                        {reference && (
                            <Link href={route('cases.cover-sheet', caseId)} className="btn btn-outline btn-sm">
                                <QrCode className="size-4" aria-hidden="true" />
                                Cover Sheet
                            </Link>
                        )}
                        {ordered.map((action) => (
                            <Button
                                key={action.key}
                                type="button"
                                size="sm"
                                variant={action.primary ? 'default' : 'outline'}
                                className="active:scale-[0.97]"
                                disabled={!action.enabled}
                                aria-expanded={INLINE.includes(action.key) ? expanded === action.key : undefined}
                                aria-describedby={action.disabled_reason ? `next-step-${action.key}-reason` : undefined}
                                onClick={() => open(action)}
                            >
                                {action.label}
                            </Button>
                        ))}
                    </>
                }
            >
                <p className="text-sm">{next.instruction}</p>

                {next.checklist.length > 0 && (
                    <ul aria-label="Checklist" className="list border-base-300 rounded-box border">
                        {next.checklist.map((item) => (
                            <ChecklistRow key={item.key} item={item} />
                        ))}
                    </ul>
                )}

                {reasons.map((action) => (
                    <p key={action.key} id={`next-step-${action.key}-reason`} className="text-base-content/70 flex items-start gap-2 text-sm">
                        <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>{action.disabled_reason}</span>
                    </p>
                ))}

                {next.actions
                    .filter((a) => a.key in SIMPLE_ROUTES)
                    .map((action) => (
                        <ConfirmAction
                            key={action.key}
                            caseId={caseId}
                            action={action}
                            open={dialog === action.key}
                            onOpenChange={(o) => setDialog(o ? action.key : null)}
                        />
                    ))}

                {next.actions.map((action) => {
                    if (action.key === 'forward_for_review' && reviewers) {
                        return (
                            <ForwardForReview
                                key={action.key}
                                caseId={caseId}
                                reviewers={reviewers}
                                open={dialog === action.key}
                                onOpenChange={(o) => setDialog(o ? action.key : null)}
                                confirm={action.confirm}
                            />
                        );
                    }
                    if (action.key === 'send_to_legal' && legalOfficers) {
                        return (
                            <SendToLegal
                                key={action.key}
                                caseId={caseId}
                                officers={legalOfficers}
                                open={dialog === action.key}
                                onOpenChange={(o) => setDialog(o ? action.key : null)}
                                confirm={action.confirm}
                            />
                        );
                    }
                    return null;
                })}
            </ActionPanel>

            {/* The checklist's Date Received form, as its own panel. */}
            {hardcopyForms.map(
                (item) =>
                    item.hardcopy_form && (
                        <HardcopyRecordForm
                            key={item.key}
                            caseId={caseId}
                            reference={reference}
                            organisation={organisation}
                            dates={item.hardcopy_form}
                        />
                    ),
            )}

            {/* The step's own form opens as the next panel in the column. */}
            {expandedAction && inlineForm(expandedAction)}

            {next.queries.length > 0 && (
                <ActionPanel
                    title="Open Queries"
                    icon={MessagesSquare}
                    actions={
                        queryActions.length > 0 ? (
                            <>
                                {queryActions.map((q) => (
                                    <Button key={q.id} type="button" size="sm" className="active:scale-[0.97]" onClick={onOpenQueries}>
                                        {q.action}
                                        <span className="sr-only">: {q.title}</span>
                                    </Button>
                                ))}
                            </>
                        ) : undefined
                    }
                >
                    <ul className="list border-base-300 rounded-box border">
                        {next.queries.map((q) => (
                            <li key={q.id} className="list-row block space-y-0.5 px-3 py-2.5 text-sm">
                                <p className="font-medium">{q.title}</p>
                                <p className="text-base-content/70">
                                    {q.status_label}
                                    {!q.action && q.waiting_on && ` · ${q.waiting_on}`}
                                </p>
                            </li>
                        ))}
                    </ul>
                </ActionPanel>
            )}
        </>
    );
}
