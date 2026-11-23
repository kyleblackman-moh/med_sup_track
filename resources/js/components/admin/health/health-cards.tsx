import { type HealthCard, type HealthTone } from '@/components/admin/health/types';
import { Link } from '@inertiajs/react';
import { ArrowRight, CircleAlert, CircleCheck, Minus, TrendingDown, TrendingUp, TriangleAlert, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

const GROUPING = new Intl.NumberFormat('en-US');

/** Static classes per server tone (never built from the tone string). Colour is always paired with text and an icon. */
const TONE: Record<HealthTone, { value: string; check: string; badge: string | null; label: string | null; icon: LucideIcon | null }> = {
    neutral: { value: 'stat-value text-3xl font-bold tabular-nums', check: 'stat-value text-xl font-semibold', badge: null, label: null, icon: null },
    success: {
        value: 'stat-value text-success text-3xl font-bold tabular-nums',
        check: 'stat-value text-success text-xl font-semibold',
        badge: 'badge badge-soft badge-success badge-sm',
        label: 'OK',
        icon: CircleCheck,
    },
    warning: {
        value: 'stat-value text-warning text-3xl font-bold tabular-nums',
        check: 'stat-value text-warning text-xl font-semibold',
        badge: 'badge badge-soft badge-warning badge-sm',
        label: 'Check',
        icon: TriangleAlert,
    },
    error: {
        value: 'stat-value text-error text-3xl font-bold tabular-nums',
        check: 'stat-value text-error text-xl font-semibold',
        badge: 'badge badge-soft badge-error badge-sm',
        label: 'Act now',
        icon: CircleAlert,
    },
};

/** The tone in words beside the label: "Check" or "Act now" (nothing for a neutral count). */
export function ToneBadge({ tone, label }: { tone: HealthTone; label?: string }) {
    const t = TONE[tone];
    if (!t.badge) return null;
    const Icon = t.icon;

    return (
        <span className={t.badge}>
            {Icon && <Icon className="size-3" aria-hidden="true" />}
            {label ?? t.label}
        </span>
    );
}

function Trend({ card }: { card: HealthCard }) {
    if (card.comparison === undefined || !card.trend) return null;
    const Icon = card.trend === 'up' ? TrendingUp : card.trend === 'down' ? TrendingDown : Minus;
    const words = card.trend === 'up' ? 'Above' : card.trend === 'down' ? 'Below' : 'Same as';

    return (
        <span className="inline-flex min-w-0 items-center gap-1">
            <Icon className="size-3.5 shrink-0" aria-hidden="true" />
            <span className="truncate">
                {words} {card.comparison_label?.toLowerCase() ?? 'the daily average'}: <span className="tabular-nums">{card.comparison}</span>
            </span>
        </span>
    );
}

/** One count tile (daisyUI stats > stat). Linked to its list when the server gives one. */
function Tile({ card, footnote }: { card: HealthCard; footnote?: ReactNode }) {
    const value = card.value === null ? 'Unknown' : GROUPING.format(card.value);
    const desc =
        card.comparison !== undefined ? (
            <Trend card={card} />
        ) : (
            (footnote ?? (card.href ? 'Open the list' : card.value === null ? 'The queue size could not be read' : 'No list behind this count'))
        );

    const body = (
        <>
            <div className="stat-title flex min-w-0 items-center justify-between gap-2 text-sm">
                <span className="truncate">{card.label}</span>
                <ToneBadge tone={card.tone} />
            </div>
            <div className={TONE[card.tone].value}>{value}</div>
            <div className="stat-desc text-base-content/70 flex min-w-0 items-center justify-between gap-2">
                <span className="min-w-0">{desc}</span>
                {card.href && <ArrowRight className="size-3.5 shrink-0" aria-hidden="true" />}
            </div>
        </>
    );

    return (
        <div className="stats bg-base-100 border-base-300 min-w-0 border shadow-xs">
            {card.href ? (
                <Link
                    href={card.href}
                    className="stat hover:bg-base-200 min-w-0 px-5 py-4 transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2"
                >
                    {body}
                </Link>
            ) : (
                <div className="stat min-w-0 px-5 py-4">{body}</div>
            )}
        </div>
    );
}

interface GroupProps {
    id: string;
    title: string;
    description: string;
    icon: LucideIcon;
    cards: HealthCard[];
    /** Extra tiles after the counts (for example the ClamAV and disk checks). */
    children?: ReactNode;
}

/** A titled group of count tiles: Users, Security or Operations. */
export function HealthGroup({ id, title, description, icon: Icon, cards, children }: GroupProps) {
    return (
        <section aria-labelledby={id} className="min-w-0 space-y-3">
            <div className="min-w-0">
                <h2 id={id} className="flex items-center gap-2 text-base font-semibold capitalize">
                    <Icon className="text-base-content/50 size-4" aria-hidden="true" />
                    {title}
                </h2>
                <p className="text-base-content/70 text-helper">{description}</p>
            </div>
            <div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {cards.map((card) => (
                    <Tile key={card.key} card={card} />
                ))}
                {children}
            </div>
        </section>
    );
}

interface CheckTileProps {
    label: string;
    value: string;
    detail: ReactNode;
    tone: HealthTone;
    icon: LucideIcon;
}

/** An infrastructure check (ClamAV, disk, audit seal): a word, not a count. */
export function CheckTile({ label, value, detail, tone, icon: Icon }: CheckTileProps) {
    return (
        <div className="stats bg-base-100 border-base-300 min-w-0 border shadow-xs">
            <div className="stat min-w-0 px-5 py-4">
                <div className="stat-figure text-base-content/40">
                    <Icon className="size-6" aria-hidden="true" />
                </div>
                <div className="stat-title flex min-w-0 items-center gap-2 text-sm">
                    <span className="truncate">{label}</span>
                    <ToneBadge tone={tone} />
                </div>
                <div className={TONE[tone].check}>{value}</div>
                <div className="stat-desc text-base-content/70 whitespace-normal">{detail}</div>
            </div>
        </div>
    );
}
