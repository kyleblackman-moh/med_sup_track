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

/**
 * The numbered parts of the request as a daisyUI tab strip (tabs-border).
 * Each part shows a check when complete, or how many items it still needs.
 * Wraps onto a second line on narrow screens; arrow keys move between tabs.
 */
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
            <div role="tablist" aria-label="Request form sections" className="tabs tabs-border border-base-300 border-b">
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
                                'tab shrink-0 gap-2 whitespace-nowrap capitalize motion-safe:transition-colors',
                                selected ? 'tab-active font-semibold' : 'font-medium',
                            )}
                        >
                            <span className="tabular-nums opacity-70">{index + 1}.</span>
                            {tab.label}
                            {complete ? (
                                <>
                                    <Check className="text-success size-4" aria-hidden />
                                    <span className="sr-only"> (complete)</span>
                                </>
                            ) : (
                                <span className="badge badge-sm badge-warning badge-soft tabular-nums">
                                    {errors.length}
                                    <span className="sr-only"> {errors.length === 1 ? 'item' : 'items'} still needed</span>
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
            <p className="text-base-content/70 flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-hidden>
                <span className="flex items-center gap-1.5">
                    <Check className="text-success size-3.5" /> Complete
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="badge badge-xs badge-warning badge-soft border-warning/40" /> Still needs information (number = items left)
                </span>
                <span>Updates each time you save.</span>
            </p>
        </div>
    );
}
