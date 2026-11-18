import { TYPE_ICONS } from '@/components/requests/type-icons';
import { StatusPill } from '@/components/ui/status-pill';
import { type CaseHeroView } from '@/types/requests';
import { CalendarDays, CircleCheck, FileText, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

/** The 44px circular white icon holder of a meta cell. */
function MetaCell({ icon: Icon, label, children }: { icon: LucideIcon; label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 items-center gap-3 lg:px-6">
            <span aria-hidden="true" className="bg-card text-primary flex size-11 shrink-0 items-center justify-center rounded-full border shadow-xs">
                <Icon className="size-5" />
            </span>
            <div className="min-w-0 space-y-1">
                <dt className="text-muted-foreground text-sm font-medium">{label}</dt>
                <dd className="text-lg leading-tight font-semibold">{children}</dd>
            </div>
        </div>
    );
}

/**
 * V2-4 Stage B: the case hero (design.md section 7). Everything shown comes
 * from the server's `hero` prop (CaseHero::for): the reference (or "Draft"
 * before there is one), the item description, the type (or, while it is
 * null, the server's type_unset_label), the real status pill and the
 * matching date label. Below lg the
 * meta cells wrap; below md they stack. Display only.
 */
export function CaseHero({ hero }: { hero: CaseHeroView }) {
    const TypeIcon = (hero.type && TYPE_ICONS[hero.type.icon]) || FileText;

    return (
        <section aria-label="Request overview" className="bg-card shadow-card rounded-2xl border px-5 py-5 md:px-7 md:py-6">
            <div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:gap-0">
                <div className="flex min-w-0 items-center gap-5 lg:flex-1 lg:pr-6">
                    {/* The icon tile: the one gradient on the page besides the sidebar (design.md section 2). */}
                    <span
                        aria-hidden="true"
                        className="from-tile-from to-tile-to text-primary-foreground shadow-card flex size-20 shrink-0 items-center justify-center rounded-[20px] bg-linear-to-b"
                    >
                        <FileText className="size-9" strokeWidth={1.75} />
                    </span>
                    <div className="min-w-0 space-y-1 md:border-l md:pl-5">
                        <p className="text-muted-foreground text-xs font-medium tracking-[0.08em] uppercase">Request</p>
                        <p className="text-[30px] leading-tight font-bold tracking-[-0.01em] tabular-nums">{hero.reference ?? 'Draft'}</p>
                        {/* V2-4b: the page's one h1 (the item description, else the server's "New Request"). */}
                        <h1 className="text-[22px] leading-snug font-semibold break-words">{hero.heading}</h1>
                    </div>
                </div>

                <dl className="grid gap-4 border-t pt-5 sm:grid-cols-2 md:grid-cols-3 lg:flex lg:divide-x lg:border-t-0 lg:border-l lg:pt-0">
                    <MetaCell icon={TypeIcon} label="Request type">
                        {hero.type ? hero.type.label : <span className="text-muted-foreground font-medium">{hero.type_unset_label}</span>}
                    </MetaCell>
                    <MetaCell icon={CircleCheck} label="Status">
                        <StatusPill label={hero.status_label} tone={hero.status_tone} className="mt-0.5" />
                    </MetaCell>
                    <MetaCell icon={CalendarDays} label={hero.date.label}>
                        <span className="tabular-nums">{hero.date.value}</span>
                    </MetaCell>
                </dl>
            </div>
        </section>
    );
}
