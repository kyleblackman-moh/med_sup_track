import { type SectionKey, type SectionStatus } from '@/types/ad-hoc';
import { Check } from 'lucide-react';

interface ProgressRailProps {
    sections: SectionStatus[];
    onJump: (key: SectionKey) => void;
}

/** Visible status text, so the marker's shape is never the only signal. */
const statusText = (section: SectionStatus) => (section.complete ? 'Complete' : section.optional ? 'Optional' : 'Not complete');

/**
 * Section navigation in form order. From 1280px it sits in its own grid column
 * and sticks inside that column, so it can never cover the form; below that it
 * is a compact bar above the sections. A check icon only where the server marks
 * the section complete, a small hollow circle otherwise, each with visible text.
 * No counts and no error colours here.
 */
export function ProgressRail({ sections, onJump }: ProgressRailProps) {
    return (
        <nav aria-label="Form sections" className="xl:sticky xl:top-6">
            <ol className="flex gap-2 overflow-x-auto xl:flex-col xl:gap-1 xl:overflow-visible">
                {sections.map((section, i) => (
                    <li key={section.key} className="shrink-0">
                        <a
                            href={`#section-${section.key}`}
                            onClick={(e) => {
                                e.preventDefault();
                                onJump(section.key);
                            }}
                            className="hover:bg-accent focus-visible:ring-ring flex min-h-11 items-start gap-2 rounded-md px-2 py-1.5 text-sm focus-visible:ring-2 focus-visible:outline-hidden md:min-h-9"
                        >
                            <span aria-hidden className="flex h-5 w-4 shrink-0 items-center justify-center">
                                {section.complete ? (
                                    <Check className="text-primary size-4" strokeWidth={2.5} />
                                ) : (
                                    <span className="border-muted-foreground size-2 rounded-full border" />
                                )}
                            </span>
                            <span className="grid leading-5">
                                <span>
                                    <span className="xl:hidden">{i + 1}. </span>
                                    {section.label}
                                </span>
                                <span className="text-muted-foreground text-xs leading-4">
                                    <span className="sr-only">, </span>
                                    {statusText(section)}
                                </span>
                            </span>
                        </a>
                    </li>
                ))}
            </ol>
        </nav>
    );
}
