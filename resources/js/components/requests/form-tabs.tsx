import { cn } from '@/lib/utils';
import { type FormConfig, type Readiness, type ReadinessItem, type Section } from '@/types/requests';
import { Check } from 'lucide-react';
import { KeyboardEvent, useRef } from 'react';
import { COMMENTS_FIELD, COMMENTS_TAB, REQUISITION_GROUPS, SECTION_TAB, type TabId } from './fields';

export interface TabDef {
    id: TabId;
    label: string;
    sections: Section[];
}

const TAB_LABELS: Record<TabId, string> = {
    details: 'Request Details',
    stock: 'Stock',
    tender: 'Tender & RFQ',
    orders: 'Awards & Orders',
    quotations: 'Quotations',
    documents: 'Documents',
};

const TAB_ORDER: TabId[] = ['details', 'stock', 'tender', 'orders', 'quotations', 'documents'];

/** Tabs for this procurement type: a tab with no fields and no allowed sections is skipped. */
export function buildTabs(config: FormConfig): TabDef[] {
    return TAB_ORDER.map((id) => ({
        id,
        label: TAB_LABELS[id],
        sections: config.allowed_sections.filter((section) => SECTION_TAB[section] === id),
    })).filter((tab) => tab.sections.length > 0 || tab.id === COMMENTS_TAB || REQUISITION_GROUPS.some((g) => g.tab === tab.id));
}

const FIELD_TAB: Record<string, TabId> = Object.fromEntries([
    ...REQUISITION_GROUPS.flatMap((group) => group.fields.map((field) => [field.name, group.tab])),
    [COMMENTS_FIELD.name, COMMENTS_TAB],
]);

/** The tab a readiness or validation key (requisition.x, quotations.0.x, documents.x) belongs to. */
export function tabForKey(key: string): TabId {
    const [head, second] = key.split('.');

    if (head === 'requisition') {
        return FIELD_TAB[second] ?? 'details';
    }
    if (head === 'documents') {
        return 'documents';
    }

    return SECTION_TAB[head as Section] ?? 'details';
}

export interface TabIssues {
    errors: ReadinessItem[];
    warnings: ReadinessItem[];
}

export function issuesFor(readiness: Readiness, tab: TabId): TabIssues {
    return {
        errors: readiness.errors.filter((item) => tabForKey(item.key) === tab),
        warnings: readiness.warnings.filter((item) => tabForKey(item.key) === tab),
    };
}

interface FormTabsProps {
    tabs: TabDef[];
    active: TabId;
    readiness: Readiness;
    onSelect: (tab: TabId) => void;
}

/** Tab strip. Wraps onto a second line on narrow screens; arrow keys move between tabs. */
export function FormTabs({ tabs, active, readiness, onSelect }: FormTabsProps) {
    const refs = useRef<Record<string, HTMLButtonElement | null>>({});

    const onKeyDown = (e: KeyboardEvent, index: number) => {
        const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (step === 0) {
            return;
        }
        e.preventDefault();
        const next = tabs[(index + step + tabs.length) % tabs.length];
        refs.current[next.id]?.focus();
        onSelect(next.id);
    };

    return (
        <div className="space-y-2">
            <div role="tablist" aria-label="Request form sections" className="flex flex-wrap gap-1 border-b">
                {tabs.map((tab, index) => {
                    const { errors } = issuesFor(readiness, tab.id);
                    const complete = errors.length === 0;
                    const selected = tab.id === active;

                    return (
                        <button
                            key={tab.id}
                            ref={(el) => {
                                refs.current[tab.id] = el;
                            }}
                            type="button"
                            role="tab"
                            id={`tab-${tab.id}`}
                            aria-selected={selected}
                            aria-controls={`panel-${tab.id}`}
                            tabIndex={selected ? 0 : -1}
                            onClick={() => onSelect(tab.id)}
                            onKeyDown={(e) => onKeyDown(e, index)}
                            className={cn(
                                '-mb-px flex shrink-0 items-center gap-2 rounded-t-md border-b-3 px-3 py-2 text-sm whitespace-nowrap transition-colors',
                                complete
                                    ? 'bg-status-info text-status-info-foreground hover:bg-selected'
                                    : 'bg-status-warning text-status-warning-foreground hover:bg-status-warning/80',
                                selected ? 'border-current font-semibold' : 'border-transparent font-medium',
                            )}
                        >
                            <span className="tabular-nums opacity-70">{index + 1}.</span>
                            {tab.label}
                            {complete ? (
                                <>
                                    <Check className="size-4" aria-hidden />
                                    <span className="sr-only"> (complete)</span>
                                </>
                            ) : (
                                <span className="bg-card text-status-warning-foreground border-status-warning-foreground/30 rounded-full border px-1.5 text-xs font-semibold">
                                    {errors.length}
                                    <span className="sr-only"> {errors.length === 1 ? 'item' : 'items'} still needed</span>
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
            <p className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-hidden>
                <span className="flex items-center gap-1.5">
                    <span className="bg-status-info-foreground size-2.5 rounded-sm" /> Complete
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="bg-status-warning-foreground size-2.5 rounded-sm" /> Still needs information (number = items left)
                </span>
                <span>Updates each time you save.</span>
            </p>
        </div>
    );
}
