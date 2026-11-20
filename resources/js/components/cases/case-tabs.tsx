import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { KeyboardEvent, useRef } from 'react';

export interface CaseTab<T extends string = string> {
    id: T;
    label: string;
}

interface CaseTabsProps<T extends string> {
    tabs: CaseTab<T>[];
    active: T;
    onSelect: (tab: T) => void;
}

/**
 * Tab strip for the staff case view (daisyUI tabs-border). One row that
 * scrolls sideways on narrow screens; arrow keys move between tabs. Neutral
 * colours only (no complete/incomplete or lateness meaning).
 */
export function CaseTabs<T extends string>({ tabs, active, onSelect }: CaseTabsProps<T>) {
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
        <div className="border-base-300 -mx-1 overflow-x-auto border-b">
            <div role="tablist" aria-label="Case sections" className="tabs tabs-border flex-nowrap px-1">
                {tabs.map((tab, index) => {
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
                            className={
                                selected
                                    ? 'tab tab-active shrink-0 font-semibold whitespace-nowrap capitalize transition-colors'
                                    : 'tab shrink-0 whitespace-nowrap capitalize transition-colors'
                            }
                        >
                            {tab.label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

interface CaseTabNavProps<T extends string> {
    tabs: CaseTab<T>[];
    active: T;
    onSelect: (tab: T) => void;
    finishHref: string;
}

/**
 * Back / Next at the end of the tab content. Moves between tabs only (it
 * never saves). On the last tab Next becomes Finish, back to the case list.
 */
export function CaseTabNav<T extends string>({ tabs, active, onSelect, finishHref }: CaseTabNavProps<T>) {
    const index = tabs.findIndex((t) => t.id === active);
    const prev = tabs[index - 1];
    const next = tabs[index + 1];

    return (
        <div className="border-base-300 flex flex-wrap items-center gap-2 border-t pt-3 [&_.btn]:capitalize">
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => prev && onSelect(prev.id)} disabled={!prev}>
                <ArrowLeft className="size-4" aria-hidden="true" />
                {prev ? prev.label : 'Back'}
            </button>
            <span className="text-base-content/60 ml-auto text-xs tabular-nums">
                {index + 1} of {tabs.length}
            </span>
            {next ? (
                <button type="button" className="btn btn-sm" onClick={() => onSelect(next.id)}>
                    {next.label}
                    <ArrowRight className="size-4" aria-hidden="true" />
                </button>
            ) : (
                <Link href={finishHref} className="btn btn-sm">
                    Back to cases
                </Link>
            )}
        </div>
    );
}
