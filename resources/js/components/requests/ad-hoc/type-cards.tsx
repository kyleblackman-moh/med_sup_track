import { TYPE_ICONS as ICONS } from '@/components/requests/type-icons';
import { RadioOption, RadioStack } from '@/components/ui/radio-group';
import { type RequestTypeOption } from '@/types/requests';

// V2-2: the request type as the first block of the form. RADIO-1: the shared
// radio rows (RadioOption) stacked one per line, never side by side, with the
// type's icon square; native radios in one fieldset (arrow keys move between
// them); the label only, no descriptions. The options, their order and their group ("Ad Hoc") come from
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

    const card = (option: RequestTypeOption) => {
        const Icon = option.icon ? ICONS[option.icon] : undefined;

        return (
            <RadioOption
                key={option.code}
                id={`${ID}-${option.code}`}
                name={ID}
                value={option.code}
                checked={value === option.code}
                onChange={onChange}
                label={option.label}
                // V2-4b item 7: a type must be chosen before the form goes on (RequestSections::REQUEST_TYPE_NEEDED).
                // No form element is submitted here, so this only tells assistive technology; validation is the server's.
                required
                icon={
                    Icon && (
                        <span
                            className="bg-accent text-accent-foreground flex size-6 shrink-0 items-center justify-center rounded-lg"
                            aria-hidden="true"
                        >
                            <Icon className="size-3.5" />
                        </span>
                    )
                }
            />
        );
    };

    // V2-5: inside section 1 "Request Type" (the numbered panel is the card); the legend repeats the title for screen readers.
    return (
        <fieldset id={ID} className="space-y-3" aria-describedby={describedBy} disabled={disabled}>
            <legend className="sr-only">Request Type</legend>

            {unselected && (
                <p id={`${ID}-choose`} className="text-muted-foreground text-sm">
                    Choose a request type to continue.
                </p>
            )}

            {groups.map((group, g) =>
                group.label ? (
                    <fieldset key={group.label} className="fieldset min-w-0 gap-2 py-0">
                        <legend className="fieldset-legend pt-0 text-sm capitalize">{group.label}</legend>
                        <RadioStack>{group.options.map(card)}</RadioStack>
                    </fieldset>
                ) : (
                    <RadioStack key={`group-${g}`}>{group.options.map(card)}</RadioStack>
                ),
            )}

            {error && (
                <p id={`${ID}-error`} role="alert" className="text-destructive-text text-sm">
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
