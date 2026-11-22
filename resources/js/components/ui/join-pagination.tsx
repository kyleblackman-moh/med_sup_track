import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface JoinPaginationProps {
    /** Names the navigation ("Case list pages"). */
    label: string;
    from: number | null;
    to: number | null;
    total: number;
    prevUrl: string | null;
    nextUrl: string | null;
    className?: string;
}

/**
 * Blueprint pagination (daisyUI join): Previous, "Showing x–y of n", Next.
 * Real links; a missing page is a disabled button. Shared by the case list and
 * the notifications page.
 */
export function JoinPagination({ label, from, to, total, prevUrl, nextUrl, className }: JoinPaginationProps) {
    return (
        <nav aria-label={label} className={className ?? 'border-base-300 flex justify-end border-t px-3 py-2'}>
            <div className="join">
                <PageLink href={prevUrl} label="Previous" icon="prev" />
                <span className="join-item border-base-300 bg-base-100 flex items-center border px-3 text-sm tabular-nums">
                    Showing {from ?? 0}–{to ?? 0} of {total}
                </span>
                <PageLink href={nextUrl} label="Next" icon="next" />
            </div>
        </nav>
    );
}

function PageLink({ href, label, icon }: { href: string | null; label: string; icon: 'prev' | 'next' }) {
    const content = (
        <>
            {icon === 'prev' && <ChevronLeft className="size-4" aria-hidden="true" />}
            {label}
            {icon === 'next' && <ChevronRight className="size-4" aria-hidden="true" />}
        </>
    );

    return href ? (
        <Link href={href} preserveScroll preserveState className="btn btn-sm join-item capitalize">
            {content}
        </Link>
    ) : (
        <button type="button" className="btn btn-sm join-item capitalize" disabled>
            {content}
        </button>
    );
}
