import { cn } from '@/lib/utils';
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
 * Tab strip for the staff case view, the same pattern as the vendor request
 * form: wraps on narrow screens, arrow keys move between tabs. Neutral token
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
        <div role="tablist" aria-label="Case sections" className="flex flex-wrap gap-1 border-b">
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
                        className={cn(
                            '-mb-px flex shrink-0 items-center gap-2 rounded-t-md border-b-[3px] px-3 py-2 text-sm whitespace-nowrap transition-colors',
                            selected
                                ? 'border-primary bg-muted text-foreground font-semibold'
                                : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground border-transparent font-medium',
                        )}
                    >
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}
