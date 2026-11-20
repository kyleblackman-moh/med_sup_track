import { type LucideIcon } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useId } from 'react';

interface ActionPanelProps {
    /** The step, in the user's words ("Record hardcopy", "Legal decision"). */
    title: string;
    /** One line on what this step does or why it is waiting. */
    description?: ReactNode;
    icon?: LucideIcon;
    /** A short state on the right of the title (a StatusPill or badge). */
    status?: ReactNode;
    /** Buttons for this step. Always rendered last, in the footer, right-aligned. */
    actions?: ReactNode;
    /** When given, the panel is the <form> itself, so a submit button in `actions` submits it. */
    onSubmit?: FormEventHandler<HTMLFormElement>;
    children?: ReactNode;
}

/**
 * The one panel pattern for every case action (Blueprint: card with a body and
 * card-actions). Title and state on top, the fields in the body, and the
 * buttons at the end in a footer row, so every role's step reads the same way.
 */
export function ActionPanel({ title, description, icon: Icon, status, actions, onSubmit, children }: ActionPanelProps) {
    const headingId = useId();
    // One root element: a <form> when the step submits, else a <section>.
    const Root = onSubmit ? 'form' : 'section';

    return (
        <Root
            aria-labelledby={headingId}
            onSubmit={onSubmit}
            noValidate={onSubmit ? true : undefined}
            className="card bg-base-100 border-base-300 border transition-shadow duration-200 hover:shadow-sm"
        >
            <div className="card-body gap-3 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <h3 id={headingId} className="card-title text-base capitalize">
                        {Icon && <Icon className="text-primary size-4 shrink-0" aria-hidden="true" />}
                        {title}
                    </h3>
                    {status}
                </div>
                {description && <div className="text-base-content/70 text-sm">{description}</div>}
                {children}
                {actions && (
                    <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 items-center justify-end border-t px-4 pt-3 [&_.btn]:capitalize">
                        {actions}
                    </div>
                )}
            </div>
        </Root>
    );
}

/** A labelled value list for a recorded outcome inside an ActionPanel. */
export function ActionFacts({ items }: { items: [string, ReactNode][] }) {
    return (
        <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[9rem_1fr]">
            {items.map(([label, value]) => (
                <div key={label} className="contents">
                    <dt className="text-base-content/60 capitalize">{label}</dt>
                    <dd className="min-w-0 font-medium whitespace-pre-line">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

/** Shared textarea classes (daisyUI textarea) for reasons and notes in action panels. */
export const actionTextareaClass = 'textarea textarea-sm w-full';
