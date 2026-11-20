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

    return (
        <div role="tablist" aria-label={label} className={cn('flex flex-wrap gap-1 border-b', className)}>
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
                            'focus-visible:ring-ring -mb-px flex min-h-11 shrink-0 items-center gap-2 rounded-t-lg border-b-[3px] px-3 py-2 text-sm whitespace-nowrap transition-colors outline-none focus-visible:ring-2 focus-visible:ring-inset md:min-h-10 motion-reduce:transition-none',
                            selected
                                ? 'border-primary bg-card text-foreground font-semibold'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground border-transparent font-medium',
                        )}
                    >
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}
