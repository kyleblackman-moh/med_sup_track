import { cn } from '@/lib/utils';
import { KeyboardEvent, useRef } from 'react';

export interface TabItem<T extends string = string> {
    id: T;
    label: string;
}

interface TabsProps<T extends string> {
    tabs: TabItem<T>[];
    active: T;
    onSelect: (tab: T) => void;
    /** Names the tab list ("Case sections"). */
    label: string;
    className?: string;
}

/**
 * OVERNIGHT-3 (B1): the shared tab strip (was the staff case view's
 * CaseTabs). ARIA tabs: role="tablist", each tab a real button with
 * aria-selected and aria-controls="panel-{id}", arrow keys move between tabs
 * (roving tabindex). Wraps on narrow screens. Neutral token colours only (no
 * complete/incomplete or lateness meaning). Each panel is
 * `role="tabpanel" id="panel-{id}" aria-labelledby="tab-{id}"`.
 */
export function Tabs<T extends string>({ tabs, active, onSelect, label, className }: TabsProps<T>) {
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

    // daisyUI tabs-border in one row that scrolls sideways on narrow screens.
    return (
        <div className={cn('border-base-300 -mx-1 overflow-x-auto border-b', className)}>
            <div role="tablist" aria-label={label} className="tabs tabs-border flex-nowrap px-1">
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
                                    ? 'tab tab-active min-h-11 shrink-0 font-semibold whitespace-nowrap capitalize transition-colors md:min-h-10'
                                    : 'tab min-h-11 shrink-0 whitespace-nowrap capitalize transition-colors md:min-h-10'
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
