import { TYPE_ICONS as ICONS } from '@/components/requests/type-icons';
import { type RequestTypeOption } from '@/types/requests';

// V2-2: the request type as cards, the first block of the form. Native radios
// (V2-2b, design.md 0.6: 14px padding, 12px radius, 14px/600 label, --shadow-card;
// selected: 1.5px --primary border, --selected background and the 3px --ring-wash
// halo; unselected hover darkens the border in 150ms, no motion when reduced)
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

/** 3 columns at lg, 2 at md, 1 below: every row's cards share one width. */
const GRID = 'grid gap-3 md:grid-cols-2 lg:grid-cols-3';

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

    const card = (option: RequestTypeOption) => {
        const Icon = option.icon ? ICONS[option.icon] : undefined;

        return (
            <label
                key={option.code}
                className="bg-card border-border shadow-card hover:border-input has-[:checked]:border-primary has-[:checked]:bg-selected has-[:checked]:shadow-card-selected has-[:focus-visible]:ring-ring flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border-[1.5px] p-3.5 text-sm font-semibold transition-[border-color,background-color,box-shadow] duration-150 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-offset-2 motion-reduce:transition-none"
            >
                {/* design.md section 7: the tinted icon square, the radio indicator, the label. */}
                {Icon && (
                    <span className="bg-accent text-accent-foreground flex size-8 shrink-0 items-center justify-center rounded-lg" aria-hidden="true">
                        <Icon className="size-4" />
                    </span>
                )}
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
    };

    return (
        <section className="bg-muted rounded-2xl p-4">
            <div className="bg-card shadow-card rounded-xl border p-5 md:p-6">
                <fieldset id={ID} className="space-y-3" aria-describedby={describedBy} disabled={disabled}>
                    <legend className="text-base font-semibold">Request Type</legend>

                    {unselected && (
                        <p id={`${ID}-choose`} className="text-muted-foreground text-sm">
                            Choose a request type to continue.
                        </p>
                    )}

                    {groups.map((group, g) =>
                        group.label ? (
                            <fieldset key={group.label} className="space-y-2">
                                <legend className="text-muted-foreground text-[13px] font-medium">{group.label}</legend>
                                <div className={GRID}>{group.options.map(card)}</div>
                            </fieldset>
                        ) : (
                            <div key={`group-${g}`} className={GRID}>
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
