import { Label } from '@/components/ui/label';
import { type RequestTypeOption } from '@/types/requests';

// Slice V2-1b: TEMPORARY request type control at the top of the form. A plain
// fieldset of native radios; V2-2 replaces it with the type cards (design.md).
// The options and the current type come from the server (type_options,
// request_type); no type code is known or checked here.

interface TypeChoiceProps {
    value: string;
    options: RequestTypeOption[];
    onChange: (code: string) => void;
    disabled: boolean;
    error?: string;
    /** Shown after a change that hid a section. */
    showHiddenNote: boolean;
}

const ID = 'request-type';

export function TypeChoice({ value, options, onChange, disabled, error, showHiddenNote }: TypeChoiceProps) {
    const groups: { label: string | null; options: RequestTypeOption[] }[] = [];
    for (const option of options) {
        const last = groups[groups.length - 1];
        if (last && last.label === option.group) {
            last.options.push(option);
        } else {
            groups.push({ label: option.group, options: [option] });
        }
    }

    const describedBy = [error ? `${ID}-error` : null, showHiddenNote ? `${ID}-note` : null].filter(Boolean).join(' ') || undefined;

    return (
        <fieldset id={ID} className="bg-card space-y-3 rounded-lg border p-4" aria-describedby={describedBy} disabled={disabled}>
            <legend className="text-sm font-semibold">Request type</legend>

            {groups.map((group, g) => (
                <div key={group.label ?? `group-${g}`} className="grid gap-1">
                    {group.label && <p className="text-muted-foreground text-xs font-medium">{group.label}</p>}
                    {group.options.map((option) => {
                        const optionId = `${ID}-${option.code}`;

                        return (
                            <div key={option.code} className="flex min-h-11 items-center gap-2 md:min-h-9">
                                <input
                                    id={optionId}
                                    type="radio"
                                    name={ID}
                                    value={option.code}
                                    checked={value === option.code}
                                    className="focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2"
                                    onChange={() => onChange(option.code)}
                                />
                                <Label htmlFor={optionId} className="font-normal">
                                    {option.label}
                                </Label>
                            </div>
                        );
                    })}
                </div>
            ))}

            {error && (
                <p id={`${ID}-error`} role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
            {showHiddenNote && (
                <p id={`${ID}-note`} role="status" className="text-muted-foreground text-sm">
                    Changing the type may hide sections. What you entered there is kept until you submit.
                </p>
            )}
        </fieldset>
    );
}
