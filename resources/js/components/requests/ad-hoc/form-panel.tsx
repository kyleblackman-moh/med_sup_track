import { SectionPanel } from '@/components/ui/section-panel';
import { cn } from '@/lib/utils';
import { type FormSectionState, type FormSectionView, type SectionIssue } from '@/types/ad-hoc';
import { CircleAlert, CircleCheck, type LucideIcon, Minus } from 'lucide-react';
import { type ReactNode } from 'react';

/** Visible state text beside the section title, so colour is never the only signal. */
const STATE_TEXT: Record<FormSectionState, string> = {
    complete: 'Complete',
    incomplete: 'Not complete',
    optional: 'Optional',
    blocked: 'Not available yet',
};

/**
 * V2-4: the state pill (design.md section 7): a light tint, a 1px border and a
 * 16px icon before the text (the text is the signal; the icon is aria-hidden).
 * Not complete is muted text with a --destructive-text icon, so it never reads
 * as a validation error (red text, and a red border and halo on the field).
 */
const STATE_PILL: Record<FormSectionState, { icon: LucideIcon; pill: string; iconTone: string }> = {
    complete: { icon: CircleCheck, pill: 'bg-status-success text-status-success-foreground', iconTone: '' },
    incomplete: { icon: CircleAlert, pill: 'bg-card text-muted-foreground', iconTone: 'text-destructive-text' },
    optional: { icon: Minus, pill: 'bg-muted text-muted-foreground', iconTone: '' },
    blocked: { icon: CircleAlert, pill: 'bg-status-warning text-status-warning-foreground', iconTone: '' },
};

function StatePill({ state, text }: { state: FormSectionState; text: string }) {
    const { icon: Icon, pill, iconTone } = STATE_PILL[state];

    return (
        <span className={cn('text-helper inline-flex min-h-7 shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-medium', pill)}>
            <Icon aria-hidden="true" className={cn('size-4 shrink-0', iconTone)} />
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
 * V2-2b: always the STACKED panel (design.md section 7). V2-4: a header row
 * (the number among the visible sections as a 32px --primary badge, the
 * title at 20px/700, the state pill on the right) above the full-width
 * white card.
 */
export function FormPanel({ section, issues, children }: FormPanelProps) {
    return (
        <SectionPanel
            id={`section-${section.key}`}
            title={section.label}
            number={section.number}
            variant="stacked"
            titleClassName="text-xl leading-tight font-bold"
            aside={<StatePill state={section.state} text={section.note ?? STATE_TEXT[section.state]} />}
            description={
                issues.length > 0 && (
                    <span className="text-destructive">
                        {issues.length} {issues.length === 1 ? 'thing' : 'things'} to fix
                    </span>
                )
            }
        >
            <div className="grid grid-cols-1 gap-4">
                {issues.length > 0 && (
                    <ul className="text-destructive list-disc space-y-0.5 pl-5 text-sm" aria-label={`${section.label}: things to fix`}>
                        {issues.map((issue) => (
                            <li key={issue.key}>{issue.message}</li>
                        ))}
                    </ul>
                )}
                {children}
            </div>
        </SectionPanel>
    );
}
