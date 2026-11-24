import { PageContent } from '@/components/page-header';
import { AwardedSupplierSection } from '@/components/requests/ad-hoc/awarded-supplier-section';
import { ExistingArrangementSection } from '@/components/requests/ad-hoc/existing-arrangement-section';
import { errorLookups, type FormApi } from '@/components/requests/ad-hoc/form-api';
import { FormPanel } from '@/components/requests/ad-hoc/form-panel';
import { fieldId, initialState, mergeSaved, newRow, toPayload, type FormState } from '@/components/requests/ad-hoc/form-state';
import { ItemTenderSection } from '@/components/requests/ad-hoc/item-tender-section';
import { QuotationSection } from '@/components/requests/ad-hoc/quotation-section';
import { RequestTypeSection } from '@/components/requests/ad-hoc/request-type-section';
import { IssuesPanel, summaryItems } from '@/components/requests/ad-hoc/review';
import { SaveChip } from '@/components/requests/ad-hoc/save-chip';
import { StockSection } from '@/components/requests/ad-hoc/stock-section';
import { useAutosave } from '@/components/requests/ad-hoc/use-autosave';
import { VariationSection } from '@/components/requests/ad-hoc/variation-section';
import { CaseHero } from '@/components/requests/case-hero';
import { DeleteDraftDialog } from '@/components/requests/delete-draft-dialog';
import { FlashMessages } from '@/components/requests/flash-messages';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import { ProgressStrip, type ProgressItem } from '@/components/ui/progress-strip';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type AdHocPageProps, type ChildSection, type FormSectionKey, type RequestFormView, type SavedFlash } from '@/types/ad-hoc';
import { Head, router } from '@inertiajs/react';
import { ArrowRight, CircleAlert, Info, LoaderCircle, Save, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState, type ButtonHTMLAttributes, type ReactNode } from 'react';

/** OVERNIGHT-3 (A2): a ready draft goes to the Review page; only the blocked state stays here. */
type Review = 'none' | 'issues';

/** The props a save, a type change or a review reloads (never the whole page). */
const FORM_PROPS = ['case', 'request_sections', 'form', 'documents'];

/** The action bar's two button styles (daisyUI btn): the one primary action, and the outline secondary actions. */
const BAR_BUTTON = {
    default: 'btn-primary',
    outline: 'btn-outline',
} as const;

/** A daisyUI button of the action bar: 44px below md, the daisyUI field height from md, label capitalised. */
function BarButton({ variant, className, ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant: keyof typeof BAR_BUTTON }) {
    return <button type="button" className={cn('btn capitalize max-md:h-11', BAR_BUTTON[variant], className)} {...props} />;
}

/** Focus a field by id, or the first control inside a table, list or fieldset with that id. */
function focusTarget(id: string) {
    const target = document.getElementById(id);
    if (!target) {
        return;
    }
    // A field inside a collapsed block (a quotation's supplier block): open it first so the field can take focus.
    for (let closed = target.closest('details:not([open])'); closed; closed = closed.parentElement?.closest('details:not([open])') ?? null) {
        (closed as HTMLDetailsElement).open = true;
    }
    const control = target.matches('input, select, textarea, button, [tabindex]')
        ? target
        : target.querySelector<HTMLElement>('input:not([disabled]), select:not([disabled]), textarea, button');
    target.scrollIntoView({ block: 'center' });
    (control ?? target).focus({ preventScroll: true });
}

/**
 * The request form (slice V2-2): one scrolling page. The sections the server
 * marks visible (V2-5: section 1 holds the type cards), numbered and in the server's order, with
 * a sticky progress strip and a sticky action bar. Local state is the source
 * of truth while editing and is autosaved; the server validates every save,
 * computes and formats every figure, and decides what is complete and what
 * is optional (the "form" prop). Issues show only after Review and submit.
 */
export default function AdHocEdit({ case: detail, request_sections, form, config, documents, errors, can }: AdHocPageProps) {
    const [state, setState] = useState<FormState>(() => initialState(detail));
    const stateRef = useRef(state);
    stateRef.current = state;

    const [showIssues, setShowIssues] = useState(false);
    const [review, setReview] = useState<Review>('none');
    const [reviewFailed, setReviewFailed] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [changingType, setChangingType] = useState(false);
    // V2-4: Save draft is pending while it waits for the autosave to finish.
    const [savingDraft, setSavingDraft] = useState(false);
    // MASTER.md section 7: Review and Submit is busy while it saves and asks the server (no second press).
    const [reviewing, setReviewing] = useState(false);
    const [typeHidSections, setTypeHidSections] = useState(false);
    // FORM-SAVE-1 (A3): the server's notice when the type was saved but other edits are pending.
    const [typeNotice, setTypeNotice] = useState<string | null>(null);
    // V2-5: the note after the previous-request answer changes from Yes to No.
    const [variationHidden, setVariationHidden] = useState(false);
    const panelRef = useRef<HTMLElement | null>(null);

    const autosave = useAutosave({
        url: route('requests.update', detail.id),
        payload: () => toPayload(stateRef.current),
        onSaved: (page) => {
            const saved = (page.props as { saved?: SavedFlash | null }).saved;
            if (saved) {
                setState((current) => mergeSaved(current, saved));
            }
        },
    });

    // FORM-SAVE-1 (A4): in-app navigation (sidebar, breadcrumbs, links) asks before leaving
    // edits that are not saved yet, or that the last save left out. In memory only; the
    // browser's own unload warning stays (useAutosave). Hover prefetches, partial reloads,
    // non-GET visits (the autosave, submit, delete) and this page itself pass.
    const unsavedRef = useRef(autosave.hasUnsaved);
    unsavedRef.current = autosave.hasUnsaved;
    useEffect(
        () =>
            router.on('before', (event) => {
                const visit = event.detail.visit;
                if (visit.method !== 'get' || visit.prefetch || visit.only.length > 0 || visit.url.pathname === window.location.pathname) {
                    return;
                }
                if (unsavedRef.current() && !window.confirm(config.messages.leave_unsaved)) {
                    event.preventDefault();
                }
            }),
        [config.messages.leave_unsaved],
    );

    const visibleKeys = (view: RequestFormView) => view.sections.filter((section) => section.visible).map((section) => section.key);

    /**
     * Save what was typed, then change the type through the server and reload
     * only the form's props. The note shows when a section that was visible
     * before the change is not visible after it.
     *
     * FORM-SAVE-1 (A3): the type is its own request and never waits on the
     * other edits being valid: a partial save carries on, and if the save
     * failed (network or server) the type is still sent, with a notice that
     * the other edits are not saved yet.
     */
    const changeType = async (code: string) => {
        const before = visibleKeys(form);
        const othersSaved = await autosave.flush();
        setTypeNotice(null);
        router.put(
            route('requests.type', detail.id),
            { procurement_type: code },
            {
                preserveScroll: true,
                only: FORM_PROPS,
                onStart: () => setChangingType(true),
                onSuccess: (page) => {
                    const after = visibleKeys((page.props as unknown as { form: RequestFormView }).form);
                    setTypeHidSections(before.some((key) => !after.includes(key)));
                    setReview('none');
                    if (!othersSaved) {
                        setTypeNotice(config.messages.type_saved_others_pending);
                    }
                },
                onFinish: () => setChangingType(false),
            },
        );
    };

    const update = (change: (current: FormState) => FormState) => {
        setState(change);
        autosave.changed();
    };

    const updateRows = (section: ChildSection, change: (rows: FormState['rows'][ChildSection]) => FormState['rows'][ChildSection]) =>
        update((current) => ({ ...current, rows: { ...current.rows, [section]: change(current.rows[section]) } }));

    const removeRow = (section: ChildSection, rowKey: string) =>
        update((current) => {
            const removed = current.rows[section].find((row) => row.key === rowKey);
            const rows = { ...current.rows, [section]: current.rows[section].filter((row) => row.key !== rowKey) };
            // An order loses its supplier when the award row goes (the server does the same).
            if (section === 'tender_awards' && removed?.id != null) {
                rows.purchase_orders = rows.purchase_orders.map((row) =>
                    row.values.award_id === String(removed.id) ? { ...row, values: { ...row.values, award_id: '' } } : row,
                );
            }

            return { ...current, rows };
        });

    const addRow = (section: ChildSection) => {
        const row = newRow(section);
        updateRows(section, (rows) => [...rows, row]);
        // Focus the new row's first control once it is on the page.
        window.setTimeout(() => document.querySelector<HTMLElement>(`[id^="${fieldId.cell(section, row.key, '')}"]`)?.focus(), 0);
    };

    const sectionByKey = new Map(form.sections.map((section) => [section.key, section]));
    const notSavedItems = autosave.partial?.items ?? [];
    const notSavedErrors = Object.fromEntries(notSavedItems.map((item) => [item.key, item.message]));

    const api: FormApi = {
        state,
        detail,
        config,
        form,
        setField: (name, value) => update((current) => ({ ...current, requisition: { ...current.requisition, [name]: value } })),
        setPreviousRequest: (value) => {
            if (state.previousRequest === true && value === false) {
                setVariationHidden(true);
            }
            update((current) => ({ ...current, previousRequest: value }));
            // Save now, so the server shows or hides Details of Variation Requested straight away.
            // Immediate: without it the save waited out the 5-second gap after the last autosave.
            window.setTimeout(() => void autosave.flush({ immediate: true }), 0);
        },
        setCell: (section, rowKey, name, value) =>
            updateRows(section, (rows) => rows.map((row) => (row.key === rowKey ? { ...row, values: { ...row.values, [name]: value } } : row))),
        addRow,
        removeRow,
        chooseRecommended: (rowKey) =>
            updateRows('quotations', (rows) => rows.map((row) => ({ ...row, values: { ...row.values, is_recommended: row.key === rowKey } }))),
        optional: (section, field) => {
            const required = sectionByKey.get(section)?.required[field];

            return required === undefined ? undefined : !required;
        },
        // FORM-SAVE-1 (A2): what the last draft save left out shows inline like any save error.
        ...errorLookups({ saveErrors: { ...(errors ?? {}), ...notSavedErrors }, sections: request_sections, showIssues }),
    };

    /**
     * V2-4: Save draft saves now through the same autosave (the same update
     * route and validation). A failure shows in the save chip with its retry.
     */
    const saveDraft = async () => {
        setSavingDraft(true);
        try {
            await autosave.flush();
        } finally {
            setSavingDraft(false);
        }
    };

    /**
     * Save everything, then ask the server what (if anything) still needs
     * doing. OVERNIGHT-3 (A2): a ready draft goes on to the Review page; a
     * blocked one stays here with the error summary (unchanged).
     */
    const startReview = async () => {
        setReviewing(true);
        const saved = await autosave.flush();
        if (!saved) {
            setReviewFailed(true);
            setReviewing(false);

            return;
        }
        setReviewFailed(false);
        // FORM-SAVE-1: Review and Submit stays strict. Fields the last save left out block it like any issue.
        const fieldsLeftOut = autosave.hasUnsaved();
        let toReviewPage = false;
        router.reload({
            only: FORM_PROPS,
            onSuccess: (page) => {
                const latest = (page.props as unknown as AdHocPageProps).request_sections;
                const blocked = fieldsLeftOut || latest.some((section) => section.issues.length > 0);
                if (!blocked) {
                    toReviewPage = true;
                    router.visit(route('requests.review', detail.id), { onFinish: () => setReviewing(false) });

                    return;
                }
                setShowIssues(true);
                setReview('issues');
                window.setTimeout(() => {
                    panelRef.current?.scrollIntoView({ block: 'start' });
                    panelRef.current?.focus();
                }, 0);
            },
            onFinish: () => {
                if (!toReviewPage) {
                    setReviewing(false);
                }
            },
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        // V2-4 (design.md section 6): the last crumb is the reference, or "New Request" before there is one.
        { title: detail.reference_no ?? 'New Request', href: route('requests.edit', detail.id) },
    ];

    const issuesOf = (key: FormSectionKey) => (showIssues ? (request_sections.find((section) => section.key === key)?.issues ?? []) : []);

    // V2-2b: every section is the stacked panel (design.md section 7).
    const body: Record<FormSectionKey, ReactNode> = {
        // V2-5: section 1, the type cards with the tender cycle and the previous-request question.
        request_type: (
            <RequestTypeSection
                api={api}
                typeChoice={
                    detail.type_options && {
                        value: detail.request_type ?? '',
                        options: detail.type_options,
                        onChange: (code) => void changeType(code),
                        disabled: changingType,
                        error: errors?.procurement_type,
                        showHiddenNote: typeHidSections,
                    }
                }
                showVariationHiddenNote={variationHidden}
            />
        ),
        item_tender: <ItemTenderSection api={api} />,
        variation_details: <VariationSection api={api} />,
        awarded_supplier: <AwardedSupplierSection api={api} />,
        existing_arrangement: <ExistingArrangementSection api={api} />,
        stock: <StockSection api={api} />,
        quotation_comparison: <QuotationSection api={api} />,
        documents: (
            <div
                id={fieldId.table('documents')}
                tabIndex={-1}
                className="rounded-box focus-visible:outline-primary outline-offset-2 focus-visible:outline-2"
            >
                <SupportingDocuments
                    caseId={detail.id}
                    groups={documents}
                    rules={config.uploads}
                    reloadProps={['documents', 'request_sections', 'form']}
                    embedded
                />
            </div>
        ),
    };

    const visible = form.sections.filter((section) => section.visible);

    // v0.4 readiness: what still blocks Review and Submit, from the last save (the server's issues, or
    // the fields that save left out). The bar counts them and links to the first; the strip marks their sections.
    const readiness = summaryItems(request_sections, notSavedItems, state);
    const toFix = readiness.length;
    const problemSections = new Set<FormSectionKey>([
        ...request_sections.filter((section) => section.issues.length > 0).map((section) => section.key),
        ...notSavedItems.map((item) => item.section),
    ]);

    /** "{n} items to fix": show every issue inline and in the summary, then go to the first one. */
    const showFirstProblem = () => {
        setShowIssues(true);
        setReview('issues');
        const first = readiness[0];
        if (first?.target) {
            window.setTimeout(() => focusTarget(first.target), 0);
        }
    };

    // V2-4b: the strip shows position (the server's visible sections, in order); completion is each section's
    // state pill. v0.4: a section with something to fix carries a marker.
    const progress: ProgressItem[] = visible.map((section) => ({
        key: section.key,
        label: section.label,
        number: section.number ?? undefined,
        href: `#section-${section.key}`,
        problem: problemSections.has(section.key),
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Request" />

            {/* V2-2c: PageContent owns the canvas padding and the 20px between the header, the strip and the first section. */}
            <PageContent>
                {/* V2-4 Stage B: the case hero (design.md section 7), from the server's hero prop. V2-4b: it
                    holds the page's one h1; there is no separate page heading. The form's actions and the
                    autosave state are in the sticky action bar (CLAUDE.md, UI). */}
                <CaseHero hero={detail.hero} />

                <FlashMessages />
                {reviewFailed && (
                    <div role="alert" className="alert alert-error alert-soft text-sm">
                        <CircleAlert aria-hidden="true" className="size-4 shrink-0" />
                        <span>Your latest changes could not be saved, so the request was not reviewed. Try again.</span>
                    </div>
                )}
                {/* FORM-SAVE-1 (A5d): the one error summary, at the top, when Review and Submit is blocked. */}
                {review === 'issues' && (
                    <IssuesPanel
                        panelRef={panelRef}
                        heading={notSavedItems.length > 0 ? (autosave.partial?.heading ?? null) : form.issue_heading}
                        items={summaryItems(request_sections, notSavedItems, state)}
                        onJump={focusTarget}
                    />
                )}
                {typeNotice && (
                    <div role="status" className="alert alert-info alert-soft text-sm">
                        <Info aria-hidden="true" className="size-4 shrink-0" />
                        <span>{typeNotice}</span>
                    </div>
                )}

                <div data-sticky-strip className="bg-base-200/95 sticky top-16 z-10 -mx-4 px-4 py-3 md:-mx-6 md:px-6 print:hidden">
                    <ProgressStrip items={progress} label="Form sections" />
                </div>

                {/* 24px between sections (design.md section 5). */}
                <div className="space-y-6">
                    {visible.map((section) => (
                        <FormPanel key={section.key} section={section} issues={issuesOf(section.key)}>
                            {body[section.key]}
                        </FormPanel>
                    ))}
                </div>

                {/* V2-4: the white card action bar at the end of the content (design.md section 7). */}
                <StickyActionBar
                    left={
                        can.edit && (
                            <BarButton variant="outline" className="btn-error" onClick={() => setDeleting(true)}>
                                <Trash2 aria-hidden="true" className="size-4" />
                                Delete Draft
                            </BarButton>
                        )
                    }
                    right={
                        <>
                            <SaveChip
                                status={autosave.status}
                                savedAt={autosave.savedAt}
                                partialText={autosave.partial?.status}
                                onRetry={autosave.retry}
                            />
                            {can.edit && (
                                <BarButton variant="outline" disabled={savingDraft} onClick={() => void saveDraft()}>
                                    <Save aria-hidden="true" className="size-4" />
                                    {savingDraft ? 'Saving…' : 'Save Draft'}
                                </BarButton>
                            )}
                            {/* v0.4: while anything blocks, the count links to the first problem and Review and Submit waits. */}
                            {toFix > 0 && (
                                <button
                                    type="button"
                                    className="btn btn-ghost text-error capitalize max-md:h-11"
                                    aria-describedby="review-submit-blocked"
                                    onClick={showFirstProblem}
                                >
                                    <CircleAlert aria-hidden="true" className="size-4" />
                                    {toFix} {toFix === 1 ? 'item' : 'items'} to fix
                                </button>
                            )}
                            {/* One primary action per view. OVERNIGHT-3 (A2): Submit Request is on the Review page. */}
                            <BarButton
                                variant="default"
                                disabled={reviewing || toFix > 0}
                                aria-busy={reviewing || undefined}
                                aria-describedby={toFix > 0 ? 'review-submit-blocked' : undefined}
                                onClick={() => void startReview()}
                            >
                                Review and Submit
                                {reviewing ? (
                                    <LoaderCircle aria-hidden="true" className="size-4 motion-safe:animate-spin" />
                                ) : (
                                    <ArrowRight aria-hidden="true" className="size-4" />
                                )}
                            </BarButton>
                        </>
                    }
                />
                <span id="review-submit-blocked" className="sr-only">
                    Review and Submit is available once nothing is left to fix.
                </span>
            </PageContent>

            <DeleteDraftDialog
                caseId={deleting ? detail.id : null}
                onClose={() => setDeleting(false)}
                // Nothing more is saved; a save already sent finishes first, so it cannot land after the delete.
                onBeforeDelete={() => {
                    autosave.stop();

                    return autosave.flush();
                }}
            />
        </AppLayout>
    );
}
