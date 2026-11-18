import { type RequestTypeOption } from '@/types/requests';

// V2-2: the request type as cards, the first block of the form. Native radios
// in one fieldset (arrow keys move between them); the label only, no
// descriptions. The options, their order and their group ("Ad Hoc") come from
// the server (type_options); no type code is known or checked here.

interface TypeCardsProps {
    /** The draft's current type code. */
    value: string;
    options: RequestTypeOption[];
    onChange: (code: string) => void;
    disabled: boolean;
    error?: string;
    /** Shown after a change that hid a section. */
    showHiddenNote: boolean;
}

const ID = 'request-type';

export function TypeCards({ value, options, onChange, disabled, error, showHiddenNote }: TypeCardsProps) {
    const groups: { label: string | null; options: RequestTypeOption[] }[] = [];
    for (const option of options) {
        const last = groups[groups.length - 1];
        if (last && last.label === option.group) {
            last.options.push(option);
        } else {
            groups.push({ label: option.group, options: [option] });
        }
    }

    // A draft whose type is not one of the options has not chosen one yet.
    const unselected = !options.some((option) => option.code === value);
    const describedBy =
        [unselected ? `${ID}-choose` : null, error ? `${ID}-error` : null, showHiddenNote ? `${ID}-note` : null].filter(Boolean).join(' ') ||
        undefined;

    const card = (option: RequestTypeOption) => (
        <label
            key={option.code}
            className="bg-card has-[:checked]:border-primary has-[:checked]:bg-selected has-[:focus-visible]:ring-ring flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm shadow-xs has-[:checked]:font-medium has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-offset-2"
        >
            <input
                type="radio"
                name={ID}
                value={option.code}
                checked={value === option.code}
                className="accent-primary size-4 shrink-0 outline-none"
                onChange={() => onChange(option.code)}
            />
            <span>{option.label}</span>
        </label>
    );

    return (
        <section className="bg-muted rounded-2xl p-4">
            <div className="bg-card rounded-xl border p-5 shadow-xs">
                <fieldset id={ID} className="space-y-3" aria-describedby={describedBy} disabled={disabled}>
                    <legend className="text-sm font-semibold">Request type</legend>

                    {unselected && (
                        <p id={`${ID}-choose`} className="text-muted-foreground text-sm">
                            Choose a request type to continue.
                        </p>
                    )}

                    {groups.map((group, g) =>
                        group.label ? (
                            <fieldset key={group.label} className="space-y-1.5">
                                <legend className="text-muted-foreground text-xs font-medium">{group.label}</legend>
                                <div className="grid gap-2 sm:grid-cols-3">{group.options.map(card)}</div>
                            </fieldset>
                        ) : (
                            <div key={`group-${g}`} className="grid gap-2 sm:grid-cols-3">
                                {group.options.map(card)}
                            </div>
                        ),
                    )}

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
            </div>
        </section>
    );
}
