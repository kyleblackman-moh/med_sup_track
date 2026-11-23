import { cn } from '@/lib/utils';
import { CalendarDays } from 'lucide-react';
import { type CSSProperties, useRef, useState } from 'react';
import { DayPicker, type Matcher } from 'react-day-picker';

/** 'YYYY-MM-DD' → a local Date (no time zone shift); null when empty or malformed. */
export function parseDay(value: string | null | undefined): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');
    if (!match) {
        return null;
    }
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

    return Number.isNaN(date.getTime()) ? null : date;
}

/** A local Date → 'YYYY-MM-DD', the value every date field sends to the server. */
export function formatDayValue(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** "2 Nov 2026": the day as the field shows it. */
function displayDay(date: Date): string {
    return date.toLocaleDateString('en-TT', { day: 'numeric', month: 'short', year: 'numeric' });
}

interface DatePickerProps {
    /** The trigger's id: the field label's htmlFor and any error jump link point at it. */
    id: string;
    /** 'YYYY-MM-DD', or '' when no date is chosen. */
    value: string;
    onChange: (value: string) => void;
    /** Earliest and latest days that can be chosen ('YYYY-MM-DD'). */
    min?: string | null;
    max?: string | null;
    invalid?: boolean;
    required?: boolean;
    /** For fields whose visible label is elsewhere (table cells). */
    ariaLabel?: string;
    describedBy?: string;
    size?: 'sm' | 'md';
    placeholder?: string;
    className?: string;
}

/**
 * The one date field (Blueprint calendar: React Day Picker in a popover
 * dropdown). The trigger looks like the form's inputs and shows the chosen
 * day; it opens a month calendar with month and year menus, Today and Clear.
 * Days outside min/max cannot be chosen. Arrow keys move through the days,
 * Enter picks one, Esc closes. The value stays 'YYYY-MM-DD'.
 */
export function DatePicker({
    id,
    value,
    onChange,
    min,
    max,
    invalid,
    required,
    ariaLabel,
    describedBy,
    size = 'md',
    placeholder = 'Choose a date',
    className,
}: DatePickerProps) {
    const popoverId = `${id}-calendar`;
    const anchor = `--${id.replace(/[^a-zA-Z0-9_-]/g, '-')}-calendar`;
    const panelRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [open, setOpen] = useState(false);
    // Bumped on every open: the calendar remounts on the chosen month and takes focus, and stays
    // mounted while the popover fades out (so it never flashes empty).
    const [opens, setOpens] = useState(0);

    const selected = parseDay(value) ?? undefined;
    const earliest = parseDay(min) ?? undefined;
    const latest = parseDay(max) ?? undefined;
    const today = new Date();
    const disabled: Matcher[] = [...(earliest ? [{ before: earliest }] : []), ...(latest ? [{ after: latest }] : [])];
    const todayAllowed = !(earliest && today < earliest) && !(latest && today > latest);

    const choose = (date: Date | undefined) => {
        onChange(date ? formatDayValue(date) : '');
        panelRef.current?.hidePopover();
        triggerRef.current?.focus();
    };

    return (
        <>
            <button
                ref={triggerRef}
                id={id}
                type="button"
                popoverTarget={popoverId}
                style={{ anchorName: anchor } as CSSProperties}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={ariaLabel ? `${ariaLabel}: ${selected ? displayDay(selected) : 'no date chosen'}` : undefined}
                aria-required={required ? true : undefined}
                aria-invalid={invalid ? true : undefined}
                aria-describedby={describedBy}
                className={cn(
                    'input w-full min-w-0 cursor-pointer justify-between text-start tabular-nums',
                    size === 'sm' ? 'input-sm' : 'max-md:h-11',
                    invalid && 'input-error',
                    className,
                )}
            >
                <span className={cn('truncate', !selected && 'text-base-content/50')}>{selected ? displayDay(selected) : placeholder}</span>
                <CalendarDays className="text-base-content/60 size-4 shrink-0" aria-hidden="true" />
            </button>

            <div
                ref={panelRef}
                id={popoverId}
                popover="auto"
                role="dialog"
                aria-label="Choose a date"
                onToggle={(e) => {
                    const isOpen = e.newState === 'open';
                    setOpen(isOpen);
                    if (isOpen) {
                        setOpens((n) => n + 1);
                    }
                }}
                className="dropdown bg-base-100 border-base-300 rounded-box mt-1 border p-2 shadow-md"
                style={{ positionAnchor: anchor } as CSSProperties}
            >
                {opens > 0 && (
                    <DayPicker
                        key={opens}
                        className="react-day-picker"
                        mode="single"
                        autoFocus
                        selected={selected}
                        onSelect={choose}
                        defaultMonth={selected ?? (todayAllowed ? today : (latest ?? earliest ?? today))}
                        disabled={disabled}
                        captionLayout="dropdown"
                        startMonth={earliest ?? new Date(today.getFullYear() - 10, 0)}
                        endMonth={latest ?? new Date(today.getFullYear() + 10, 11)}
                        showOutsideDays
                    />
                )}
                <div className="border-base-300 mt-1 flex justify-end gap-2 border-t px-1 pt-2">
                    {value && (
                        <button type="button" className="btn btn-ghost btn-sm capitalize" onClick={() => choose(undefined)}>
                            Clear
                        </button>
                    )}
                    <button type="button" className="btn btn-sm capitalize" disabled={!todayAllowed} onClick={() => choose(today)}>
                        Today
                    </button>
                </div>
            </div>
        </>
    );
}
