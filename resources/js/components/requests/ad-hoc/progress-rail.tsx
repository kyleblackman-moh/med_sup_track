import { cn } from '@/lib/utils';
import { type SectionKey, type SectionStatus } from '@/types/ad-hoc';
import { Check } from 'lucide-react';

interface ProgressRailProps {
    sections: SectionStatus[];
    onJump: (key: SectionKey) => void;
}

/**
 * Section navigation in form order: a sticky column on desktop, a compact
 * scrolling bar on mobile. A tick only where the server marks the section
 * complete; a neutral dot otherwise. No counts and no error colours here.
 */
export function ProgressRail({ sections, onJump }: ProgressRailProps) {
    return (
        <nav aria-label="Form sections" className="bg-background sticky top-16 z-10 -mx-4 border-b px-4 py-2 lg:top-20 lg:mx-0 lg:border-0 lg:p-0">
            <ol className="flex gap-2 overflow-x-auto lg:flex-col lg:gap-1">
                {sections.map((section, i) => (
                    <li key={section.key} className="shrink-0">
                        <a
                            href={`#section-${section.key}`}
                            onClick={(e) => {
                                e.preventDefault();
                                onJump(section.key);
                            }}
                            className="hover:bg-accent focus-visible:ring-ring flex min-h-11 items-center gap-2 rounded-md px-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden md:min-h-9"
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'flex size-5 shrink-0 items-center justify-center rounded-full border text-xs',
                                    section.complete ? 'bg-primary border-primary text-primary-foreground' : 'border-input',
                                )}
                            >
                                {section.complete ? <Check className="size-3" /> : <span className="bg-muted-foreground size-1.5 rounded-full" />}
                            </span>
                            <span>
                                <span className="lg:hidden">{i + 1}. </span>
                                {section.label}
                                {section.optional && <span className="text-muted-foreground"> (optional)</span>}
                                <span className="sr-only">{section.complete ? ', complete' : ', not complete'}</span>
                            </span>
                        </a>
                    </li>
                ))}
            </ol>
        </nav>
    );
}
