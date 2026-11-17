import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import { type SectionStatus } from '@/types/ad-hoc';
import { ChevronDown } from 'lucide-react';
import { type ReactNode } from 'react';

interface FormSectionProps {
    /** Position in the form (1-6), part of the heading text. */
    number: number;
    status: SectionStatus;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Show the server's issues (only after Review and submit). */
    showIssues: boolean;
    /** Text for the collapsed header; defaults to the server summary. */
    children: ReactNode;
}

/**
 * One collapsible section of the AD_HOC form. The header is a real button
 * (Radix sets aria-expanded and aria-controls). Collapsed, it shows the
 * server's one-line summary. Issues appear only after Review and submit.
 */
export function FormSection({ number, status, open, onOpenChange, showIssues, children }: FormSectionProps) {
    const headingId = `section-${status.key}-heading`;
    const issues = showIssues ? status.issues : [];

    return (
        <section id={`section-${status.key}`} aria-labelledby={headingId} className="bg-card scroll-mt-24 rounded-lg border shadow-sm">
            <Collapsible open={open} onOpenChange={onOpenChange}>
                <h2 id={headingId} className="text-base font-semibold">
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="focus-visible:ring-ring flex min-h-14 w-full items-center gap-3 rounded-lg px-6 py-4 text-left focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <span className="flex-1">
                                <span className="block">
                                    {number}. {status.label}
                                    {status.optional && <span className="text-muted-foreground font-normal"> (optional)</span>}
                                </span>
                                {!open && <span className="text-muted-foreground block text-sm font-normal">{status.summary}</span>}
                            </span>
                            {issues.length > 0 && (
                                <span className="text-destructive text-sm font-normal">
                                    {issues.length} {issues.length === 1 ? 'thing' : 'things'} to fix
                                </span>
                            )}
                            <ChevronDown
                                className={cn('size-4 shrink-0 transition-transform motion-reduce:transition-none', open && 'rotate-180')}
                                aria-hidden
                            />
                        </button>
                    </CollapsibleTrigger>
                </h2>
                <CollapsibleContent className="space-y-6 px-6 pb-6">
                    {issues.length > 0 && (
                        <ul className="text-destructive list-disc space-y-0.5 pl-5 text-sm" aria-label={`${status.label}: things to fix`}>
                            {issues.map((issue) => (
                                <li key={issue.key}>{issue.message}</li>
                            ))}
                        </ul>
                    )}
                    {children}
                </CollapsibleContent>
            </Collapsible>
        </section>
    );
}
