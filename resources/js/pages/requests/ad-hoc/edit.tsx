import { errorLookups, type FormApi } from '@/components/requests/ad-hoc/form-api';
import { FormSection } from '@/components/requests/ad-hoc/form-section';
import { fieldId, initialState, mergeSaved, newRow, toPayload, type FormState } from '@/components/requests/ad-hoc/form-state';
import { ItemSection } from '@/components/requests/ad-hoc/item-section';
import { ProgressRail } from '@/components/requests/ad-hoc/progress-rail';
import { SaveChip } from '@/components/requests/ad-hoc/save-chip';
import { StockSection } from '@/components/requests/ad-hoc/stock-section';
import { TenderSection } from '@/components/requests/ad-hoc/tender-section';
import { useAutosave } from '@/components/requests/ad-hoc/use-autosave';
import { StatusBadge } from '@/components/requests/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AdHocPageProps, type ChildSection, type SavedFlash, type SectionKey } from '@/types/ad-hoc';
import { Head, router } from '@inertiajs/react';
import { useRef, useState, type ReactNode } from 'react';

const FIRST_OPEN: Record<SectionKey, boolean> = { item: true, tender: false, stock: false, quotations: false, price: false, documents: false };

/**
 * The AD_HOC request form (slice F2): one page, six collapsible sections, a
 * progress rail and autosave. Local state is the source of truth while
 * editing; the server validates every save, computes every figure and decides
 * what is complete (the "sections" prop). Issues show only after Review and submit.
 */
export default function AdHocEdit({ case: detail, sections, config, errors }: AdHocPageProps) {
    const [state, setState] = useState<FormState>(() => initialState(detail));
    const stateRef = useRef(state);
    stateRef.current = state;

    const [open, setOpen] = useState(FIRST_OPEN);
    const [showIssues, setShowIssues] = useState(false);

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
    };

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
        update((current) => ({ ...current, rows: { ...current.rows, [section]: [...current.rows[section], row] } }));
        // Focus the new row's first input once it is on the page.
        window.setTimeout(() => {
            const first = document.querySelector<HTMLElement>(`[id^="${fieldId.cell(section, row.key, '')}"]`);
            first?.focus();
        }, 0);
    };

    const api: FormApi = {
        state,
        detail,
        config,
        setField: (name, value) => update((current) => ({ ...current, requisition: { ...current.requisition, [name]: value } })),
        setCell: (section, rowKey, name, value) =>
            update((current) => ({
                ...current,
                rows: {
                    ...current.rows,
                    [section]: current.rows[section].map((row) => (row.key === rowKey ? { ...row, values: { ...row.values, [name]: value } } : row)),
                },
            })),
        addRow,
        removeRow,
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

    const review = async () => {
        await autosave.flush();
        router.reload({ only: ['sections'], onSuccess: () => setShowIssues(true) });
    };

    const status = (key: SectionKey) => sections.find((section) => section.key === key)!;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        { title: 'Edit draft', href: route('requests.edit', detail.id) },
    ];

    const body: Record<SectionKey, ReactNode> = {
        item: <ItemSection api={api} />,
        tender: <TenderSection api={api} />,
        stock: <StockSection api={api} />,
        quotations: <p className="text-muted-foreground text-sm">Quotations are added in the next update.</p>,
        price: <p className="text-muted-foreground text-sm">The price comparison is added in the next update.</p>,
        documents: <p className="text-muted-foreground text-sm">Documents are added in the next update.</p>,
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ad hoc request" />

            <div className="mx-auto w-full max-w-6xl px-4 pt-4 pb-12">
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
                        <Button type="button" className="h-11 md:h-9" onClick={() => void review()}>
                            Review and submit
                        </Button>
                    </div>
                </header>

                <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_14rem]">
                    <div className="lg:col-start-2 lg:row-start-1">
                        <ProgressRail sections={sections} onJump={(key) => jump(key)} />
                    </div>

                    <div className="space-y-4 lg:col-start-1 lg:row-start-1">
                        {sections.map((section) => (
                            <FormSection
                                key={section.key}
                                status={status(section.key)}
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
        </AppLayout>
    );
}
