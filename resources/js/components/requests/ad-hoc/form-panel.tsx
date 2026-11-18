import { SectionPanel } from '@/components/ui/section-panel';
import { type FormSectionState, type FormSectionView, type SectionIssue } from '@/types/ad-hoc';
import { type ReactNode } from 'react';

/** Visible state text beside the section title, so colour is never the only signal. */
const STATE_TEXT: Record<FormSectionState, string> = {
    complete: 'Complete',
    incomplete: 'Not complete',
    optional: 'Optional',
    blocked: 'Not available yet',
};

interface FormPanelProps {
    section: FormSectionView;
    /** The server's issues for this section, shown only after Review and submit. */
    issues: SectionIssue[];
    variant?: 'stacked' | 'split';
    children: ReactNode;
}

/**
 * V2-2: one numbered section of the request form (never collapsible). The
 * number, label, state and note all come from the server (the "form" prop).
 * The anchor is "section-<key>", the target of the progress strip's links.
 */
export function FormPanel({ section, issues, variant = 'stacked', children }: FormPanelProps) {
    return (
        <SectionPanel
            id={`section-${section.key}`}
            title={`${section.number}. ${section.label}`}
            variant={variant}
            aside={<span className="text-muted-foreground shrink-0 text-xs">{section.note ?? STATE_TEXT[section.state]}</span>}
            description={
                issues.length > 0 && (
                    <span className="text-destructive">
                        {issues.length} {issues.length === 1 ? 'thing' : 'things'} to fix
                    </span>
                )
            }
        >
            <div className="grid grid-cols-1 gap-6">
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
