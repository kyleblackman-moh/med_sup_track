import { Button } from '@/components/ui/button';
import { Plus, Trash2 } from 'lucide-react';
import { type ReactNode } from 'react';

interface AddRowButtonProps {
    label: string;
    onClick: () => void;
    disabled: boolean;
    cap: number;
    /** V2-5: "text" is a text (link-style) button, as "Add Another Variation". Default: outline. */
    variant?: 'outline' | 'text';
}

/** V2-2: the "Add ..." button at the bottom of an entry table, left aligned. */
export function AddRowButton({ label, onClick, disabled, cap, variant = 'outline' }: AddRowButtonProps) {
    return (
        <div className="flex flex-wrap items-center gap-3">
            <Button
                type="button"
                variant={variant === 'text' ? 'link' : 'outline'}
                size="sm"
                className={variant === 'text' ? 'px-0 font-semibold' : undefined}
                onClick={onClick}
                disabled={disabled}
            >
                <Plus aria-hidden />
                {label}
            </Button>
            {disabled && <span className="text-muted-foreground text-[13px]">This list is full ({cap} rows).</span>}
        </div>
    );
}

/** The remove icon at the end of a row; the label names the row ("Remove supplier row 2"). */
export function RemoveRowButton({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <Button type="button" variant="ghost" size="icon" aria-label={label} onClick={onClick}>
            <Trash2 aria-hidden />
        </Button>
    );
}

/** Shown in an entry table with no rows. */
export function RowsNote({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground px-4 py-4 text-sm">{children}</p>;
}
