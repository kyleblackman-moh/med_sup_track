import { PageContent } from '@/components/page-header';
import { AwardedSupplierSection } from '@/components/requests/ad-hoc/awarded-supplier-section';
import { ExistingArrangementSection } from '@/components/requests/ad-hoc/existing-arrangement-section';
import { errorLookups, type FormApi } from '@/components/requests/ad-hoc/form-api';
import { FormPanel } from '@/components/requests/ad-hoc/form-panel';
import { fieldId, initialState, mergeSaved, newRow, toPayload, type FormState } from '@/components/requests/ad-hoc/form-state';
import { ItemTenderSection } from '@/components/requests/ad-hoc/item-tender-section';
import { QuotationSection } from '@/components/requests/ad-hoc/quotation-section';
import { RequestTypeSection } from '@/components/requests/ad-hoc/request-type-section';
import { IssuesPanel, SubmitDialog, SummaryPanel } from '@/components/requests/ad-hoc/review';
import { SaveChip } from '@/components/requests/ad-hoc/save-chip';
import { StockSection } from '@/components/requests/ad-hoc/stock-section';
import { useAutosave } from '@/components/requests/ad-hoc/use-autosave';
import { VariationSection } from '@/components/requests/ad-hoc/variation-section';
import { CaseHero } from '@/components/requests/case-hero';
import { DeleteDraftDialog } from '@/components/requests/delete-draft-dialog';
import { FlashMessages } from '@/components/requests/flash-messages';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import { Button } from '@/components/ui/button';
import { ProgressStrip, type ProgressItem } from '@/components/ui/progress-strip';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AdHocPageProps, type ChildSection, type FormSectionKey, type RequestFormView, type SavedFlash } from '@/types/ad-hoc';
import { Head, router } from '@inertiajs/react';
import { ArrowRight, Save, Trash2 } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';

type Review = 'none' | 'issues' | 'ready';

/** The props a save, a type change or a review reloads (never the whole page). */
const FORM_PROPS = ['case', 'request_sections', 'form', 'documents'];

/** Focus a field by id, or the first control inside a table, list or fieldset with that id. */
function focusTarget(id: string) {
    const target = document.getElementById(id);
    if (!target) {
        return;
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
export default function AdHocEdit({
    case: detail,
    request_sections,
    form,
    summary,
    config,
    documents,
    hardcopy_notice,
    errors,
    can,
}: AdHocPageProps) {
    const [state, setState] = useState<FormState>(() => initialState(detail));
    const stateRef = useRef(state);
    stateRef.current = state;

    const [showIssues, setShowIssues] = useState(false);
    const [review, setReview] = useState<Review>('none');
    const [reviewFailed, setReviewFailed] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [changingType, setChangingType] = useState(false);
    // V2-4: Save draft is pending while it waits for the autosave to finish.
    const [savingDraft, setSavingDraft] = useState(false);
    const [typeHidSections, setTypeHidSections] = useState(false);
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

    const visibleKeys = (view: RequestFormView) => view.sections.filter((section) => section.visible).map((section) => section.key);

    /**
     * Save what was typed, then change the type through the server and reload
     * only the form's props. The note shows when a section that was visible
     * before the change is not visible after it.
     */
    const changeType = async (code: string) => {
        const before = visibleKeys(form);
        if (!(await autosave.flush())) {
            setReviewFailed(true);

            return;
        }
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
                },
                onFinish: () => setChangingType(false),
            },
        );
    };

    const update = (change: (current: FormState) => FormState) => {
        setState(change);
        autosave.changed();
        // The summary shows what was saved; a change makes it out of date.
        setReview((current) => (current === 'ready' ? 'none' : current));
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

    const api: FormApi = {
        state,
        detail,
        config,
        form,
        setField: (name, value) => update((current) => ({ ...current, requisition: { ...current.requisition, [name]: value } })),
        setVariation: (name, value) => update((current) => ({ ...current, variation: { ...current.variation, [name]: value } })),
        setPreviousRequest: (value) => {
            if (state.previousRequest === true && value === false) {
                setVariationHidden(true);
            }
            update((current) => ({ ...current, previousRequest: value }));
            // Save now, so the server shows or hides Details of Variation Requested straight away.
            window.setTimeout(() => void autosave.flush(), 0);
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
        ...errorLookups({ saveErrors: errors ?? {}, sections: request_sections, showIssues }),
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

    /** Save everything, then ask the server what (if anything) still needs doing. */
    const startReview = async () => {
        const saved = await autosave.flush();
        if (!saved) {
            setReviewFailed(true);

            return;
        }
        setReviewFailed(false);
        router.reload({
            // V2-3: plus the read-only summary (an optional prop, sent only when asked for).
            only: [...FORM_PROPS, 'summary'],
            onSuccess: (page) => {
                const latest = (page.props as unknown as AdHocPageProps).request_sections;
                setShowIssues(true);
                setReview(latest.some((section) => section.issues.length > 0) ? 'issues' : 'ready');
                window.setTimeout(() => {
                    panelRef.current?.scrollIntoView({ block: 'start' });
                    panelRef.current?.focus();
                }, 0);
            },
        });
    };

    const submit = () =>
        router.post(
            route('requests.submit', detail.id),
            {},
            {
                onStart: () => setSubmitting(true),
                // Refused (something changed meanwhile): close, then show what the server says.
                onError: () => {
                    setConfirming(false);
                    void startReview();
                },
                onFinish: () => setSubmitting(false),
            },
        );

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
                className="focus-visible:ring-ring rounded-md focus-visible:ring-2 focus-visible:outline-hidden"
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

    // V2-4b: the strip shows position only (the server's visible sections, in order); completion is each section's state pill.
    const progress: ProgressItem[] = visible.map((section) => ({
        key: section.key,
        label: section.label,
        number: section.number ?? undefined,
        href: `#section-${section.key}`,
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
                    <p role="alert" className="text-destructive text-sm">
                        Your latest changes could not be saved, so the request was not reviewed. Try again.
                    </p>
                )}

                <div className="bg-background/95 sticky top-0 z-10 -mx-4 px-4 py-3 backdrop-blur-sm md:-mx-6 md:px-6 print:hidden">
                    <ProgressStrip items={progress} label="Form sections" />
                </div>

                {/* 24px between sections (design.md section 5). */}
                <div className="space-y-6">
                    {visible.map((section) => (
                        <FormPanel key={section.key} section={section} issues={issuesOf(section.key)}>
                            {body[section.key]}
                        </FormPanel>
                    ))}

                    {/* The review result sits just above the action bar that asked for it. */}
                    {review === 'issues' && <IssuesPanel panelRef={panelRef} sections={request_sections} state={state} onJump={focusTarget} />}
                    {review === 'ready' && summary && <SummaryPanel panelRef={panelRef} summary={summary} onSubmit={() => setConfirming(true)} />}
                </div>

                {/* V2-4: the white card action bar at the end of the content (design.md section 7). */}
                <StickyActionBar
                    left={
                        can.edit && (
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                className="text-destructive-text border-destructive hover:bg-destructive-wash hover:text-destructive-text px-4 text-[15px] font-semibold"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 aria-hidden="true" />
                                Delete Draft
                            </Button>
                        )
                    }
                    right={
                        <>
                            <SaveChip status={autosave.status} savedAt={autosave.savedAt} onRetry={autosave.retry} />
                            {can.edit && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="lg"
                                    className="text-primary hover:text-primary-strong px-4 text-[15px] font-semibold"
                                    disabled={savingDraft}
                                    onClick={() => void saveDraft()}
                                >
                                    <Save aria-hidden="true" />
                                    {savingDraft ? 'Saving…' : 'Save Draft'}
                                </Button>
                            )}
                            {/* One primary action per view: once the summary is up, Submit is the primary one. */}
                            <Button
                                type="button"
                                variant={review === 'ready' ? 'outline' : 'default'}
                                size="lg"
                                className="px-5 text-[15px] font-semibold"
                                onClick={() => void startReview()}
                            >
                                Review and Submit
                                <ArrowRight aria-hidden="true" />
                            </Button>
                        </>
                    }
                />
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

            <SubmitDialog
                open={confirming}
                onOpenChange={setConfirming}
                hardcopyNotice={hardcopy_notice}
                submitting={submitting}
                onConfirm={submit}
            />
        </AppLayout>
    );
}
