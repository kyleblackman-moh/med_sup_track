import { errorLookups, type FormApi } from '@/components/requests/ad-hoc/form-api';
import { FormSection } from '@/components/requests/ad-hoc/form-section';
import { fieldId, initialState, mergeSaved, newRow, toPayload, type FormState } from '@/components/requests/ad-hoc/form-state';
import { ItemSection } from '@/components/requests/ad-hoc/item-section';
import { PriceSection } from '@/components/requests/ad-hoc/price-section';
import { ProgressRail } from '@/components/requests/ad-hoc/progress-rail';
import { QuotationsSection } from '@/components/requests/ad-hoc/quotations-section';
import { IssuesPanel, SubmitDialog, SummaryPanel } from '@/components/requests/ad-hoc/review';
import { SaveChip } from '@/components/requests/ad-hoc/save-chip';
import { StockSection } from '@/components/requests/ad-hoc/stock-section';
import { TenderSection } from '@/components/requests/ad-hoc/tender-section';
import { useAutosave } from '@/components/requests/ad-hoc/use-autosave';
import { FlashMessages } from '@/components/requests/flash-messages';
import { StatusBadge } from '@/components/requests/status-badge';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AdHocPageProps, type ChildSection, type SavedFlash, type SectionKey, type SectionStatus } from '@/types/ad-hoc';
import { Head, router } from '@inertiajs/react';
import { useRef, useState, type ReactNode } from 'react';

const FIRST_OPEN: Record<SectionKey, boolean> = { item: true, tender: false, stock: false, quotations: false, price: false, documents: false };

type Review = 'none' | 'issues' | 'ready';

/**
 * The AD_HOC request form (slice F2): one page, six collapsible sections, a
 * progress rail and autosave. Local state is the source of truth while
 * editing; the server validates every save, computes every figure and decides
 * what is complete (the "sections" prop). Issues show only after Review and
 * submit, which saves first, then asks the server.
 */
export default function AdHocEdit({ case: detail, sections, config, documents, hardcopy_notice, errors }: AdHocPageProps) {
    const [state, setState] = useState<FormState>(() => initialState(detail));
    const stateRef = useRef(state);
    stateRef.current = state;

    const [open, setOpen] = useState(FIRST_OPEN);
    const [showIssues, setShowIssues] = useState(false);
    const [review, setReview] = useState<Review>('none');
    const [reviewFailed, setReviewFailed] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [submitting, setSubmitting] = useState(false);
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

    const api: FormApi = {
        state,
        detail,
        config,
        setField: (name, value) => update((current) => ({ ...current, requisition: { ...current.requisition, [name]: value } })),
        setCell: (section, rowKey, name, value) =>
            updateRows(section, (rows) => rows.map((row) => (row.key === rowKey ? { ...row, values: { ...row.values, [name]: value } } : row))),
        addRow,
        removeRow,
        chooseRecommended: (rowKey) =>
            updateRows('quotations', (rows) => rows.map((row) => ({ ...row, values: { ...row.values, is_recommended: row.key === rowKey } }))),
        ...errorLookups({ saveErrors: errors ?? {}, sections, showIssues }),
    };

    const setSectionOpen = (key: SectionKey, next: boolean) => {
        if (!next) {
            void autosave.flush(); // save what was typed before the section closes
        }
        setOpen((current) => ({ ...current, [key]: next }));
    };

    const jump = (key: SectionKey, focusId?: string) => {
        setOpen((current) => ({ ...current, [key]: true }));
        window.setTimeout(() => {
            document.getElementById(`section-${key}`)?.scrollIntoView({ block: 'start' });
            const target = (focusId && document.getElementById(focusId)) || document.querySelector<HTMLElement>(`#section-${key}-heading button`);
            target?.focus();
        }, 0);
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
            only: ['sections', 'case', 'documents'],
            onSuccess: (page) => {
                const latest = (page.props as unknown as { sections: SectionStatus[] }).sections;
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
        { title: 'Edit draft', href: route('requests.edit', detail.id) },
    ];

    const body: Record<SectionKey, ReactNode> = {
        item: <ItemSection api={api} />,
        tender: <TenderSection api={api} />,
        stock: <StockSection api={api} />,
        quotations: <QuotationsSection api={api} />,
        price: <PriceSection api={api} />,
        documents: (
            <div
                id={fieldId.table('documents')}
                tabIndex={-1}
                className="focus-visible:ring-ring rounded-md focus-visible:ring-2 focus-visible:outline-hidden"
            >
                <SupportingDocuments caseId={detail.id} groups={documents} rules={config.uploads} reloadProps={['documents', 'sections']} embedded />
            </div>
        ),
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ad hoc request" />

            <div className="mx-auto w-full max-w-[96rem] min-w-0 px-4 pt-4 pb-12 md:px-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-lg font-semibold">Ad hoc purchase request</h1>
                        <p className="text-muted-foreground flex items-center gap-2 text-sm">
                            {detail.reference_no ? <span className="font-mono text-xs">{detail.reference_no}</span> : <span>Draft</span>}
                            <StatusBadge label={detail.status_label} group={detail.status_group} />
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <SaveChip status={autosave.status} savedAt={autosave.savedAt} onRetry={autosave.retry} />
                        {/* One primary action per view: once the summary is up, Submit is the primary one. */}
                        <Button
                            type="button"
                            variant={review === 'ready' ? 'outline' : 'default'}
                            className="h-11 md:h-9"
                            onClick={() => void startReview()}
                        >
                            Review and submit
                        </Button>
                    </div>
                </header>

                <div className="mt-4 space-y-2">
                    <FlashMessages />
                    {reviewFailed && (
                        <p role="alert" className="text-destructive text-sm">
                            Your latest changes could not be saved, so the request was not reviewed. Try again.
                        </p>
                    )}
                </div>

                {/* Two columns from 1280px: the form, then the rail in its own column (it sticks
                    inside that column, so it cannot cover the form). min-w-0 stops wide content
                    from widening the form column. Below 1280px the rail is a bar above the form. */}
                <div className="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_15rem]">
                    <div className="bg-background sticky top-0 z-10 -mx-4 min-w-0 border-b px-4 py-2 md:-mx-6 md:px-6 xl:static xl:col-start-2 xl:row-start-1 xl:mx-0 xl:border-0 xl:p-0">
                        <ProgressRail sections={sections} onJump={(key) => jump(key)} />
                    </div>

                    <div className="min-w-0 space-y-4 xl:col-start-1 xl:row-start-1">
                        {review === 'issues' && <IssuesPanel panelRef={panelRef} sections={sections} state={state} onJump={jump} />}
                        {review === 'ready' && (
                            <SummaryPanel
                                panelRef={panelRef}
                                detail={detail}
                                config={config}
                                documents={documents}
                                onSubmit={() => setConfirming(true)}
                            />
                        )}

                        {sections.map((section) => (
                            <FormSection
                                key={section.key}
                                status={section}
                                open={open[section.key]}
                                onOpenChange={(next) => setSectionOpen(section.key, next)}
                                showIssues={showIssues}
                            >
                                {body[section.key]}
                            </FormSection>
                        ))}
                    </div>
                </div>
            </div>

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
