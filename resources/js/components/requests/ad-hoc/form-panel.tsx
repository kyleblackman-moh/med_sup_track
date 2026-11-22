import { cn } from '@/lib/utils';
import { type FormSectionState, type FormSectionView, type SectionIssue } from '@/types/ad-hoc';
import { CircleAlert, CircleCheck, type LucideIcon, Minus } from 'lucide-react';
import { type ReactNode, useId } from 'react';

/** Visible state text beside the section title, so colour is never the only signal. */
const STATE_TEXT: Record<FormSectionState, string> = {
    complete: 'Complete',
    incomplete: 'Not complete',
    optional: 'Optional',
    blocked: 'Not available yet',
};

/**
 * The state badge (daisyUI badge): an icon before the text (the text is the
 * signal; the icon is aria-hidden). Not complete is a plain outline badge with
 * an error-coloured icon, so it never reads as a validation error (that is the
 * error colour on the field itself).
 */
const STATE_BADGE: Record<FormSectionState, { icon: LucideIcon; badge: string; iconTone: string }> = {
    complete: { icon: CircleCheck, badge: 'badge-soft badge-success', iconTone: '' },
    incomplete: { icon: CircleAlert, badge: 'badge-outline text-base-content/70', iconTone: 'text-error' },
    optional: { icon: Minus, badge: 'badge-ghost text-base-content/70', iconTone: '' },
    blocked: { icon: CircleAlert, badge: 'badge-soft badge-warning', iconTone: '' },
};

function StateBadge({ state, text }: { state: FormSectionState; text: string }) {
    const { icon: Icon, badge, iconTone } = STATE_BADGE[state];

    return (
        <span className={cn('badge shrink-0 gap-1.5 font-medium capitalize', badge)}>
            <Icon aria-hidden="true" className={cn('size-3.5 shrink-0', iconTone)} />
            {text}
        </span>
    );
}

interface FormPanelProps {
    section: FormSectionView;
    /** The server's issues for this section, shown only after Review and submit. */
    issues: SectionIssue[];
    children: ReactNode;
}

/**
 * V2-2: one numbered section of the request form (never collapsible). The
 * number, label, state and note all come from the server (the "form" prop).
 * The anchor is "section-<key>", the target of the progress strip's links.
 * Blueprint (record create/edit form): a flat daisyUI card per section. Its
 * ruled header bar holds the number disc (the position among the visible
 * sections), the title and the state badge, so readiness reads down the left
 * edge like the CMO's checklist; the fields fill the card body.
 */
export function FormPanel({ section, issues, children }: FormPanelProps) {
    const headingId = useId();
    const numbered = section.number !== undefined && section.number !== null;

    return (
        <section id={`section-${section.key}`} aria-labelledby={headingId} className="card bg-base-100 border-base-300 min-w-0 border">
            <div className="border-base-300 bg-base-200 rounded-t-box flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b px-4 py-3 md:px-5">
                <div className="min-w-0 space-y-0.5">
                    <h2 id={headingId} className="card-title text-lg leading-tight font-bold capitalize">
                        {numbered && (
                            <>
                                <span
                                    aria-hidden="true"
                                    className="bg-primary text-primary-content flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-bold tabular-nums"
                                >
                                    {section.number}
                                </span>
                                <span className="sr-only">{section.number}. </span>
                            </>
                        )}
                        {section.label}
                    </h2>
                    {issues.length > 0 && (
                        <p className="text-error text-sm">
                            {issues.length} {issues.length === 1 ? 'thing' : 'things'} to fix
                        </p>
                    )}
                </div>
                <StateBadge state={section.state} text={section.note ?? STATE_TEXT[section.state]} />
            </div>
            <div className="card-body min-w-0 gap-4 p-4 md:p-5">
                {issues.length > 0 && (
                    <ul className="text-error grid gap-1 text-sm" aria-label={`${section.label}: things to fix`}>
                        {issues.map((issue) => (
                            <li key={issue.key} className="flex min-w-0 items-start gap-1.5">
                                <CircleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                                <span className="min-w-0 break-words">{issue.message}</span>
                            </li>
                        ))}
                    </ul>
                )}
                {children}
            </div>
        </section>
    );
}
