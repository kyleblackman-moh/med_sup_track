import { useCountUp } from '@/components/dashboard/motion';
import { Link } from '@inertiajs/react';
import { FileCheck, FileClock, FolderOpen, Inbox, type LucideIcon } from 'lucide-react';

/** One stat tile, built and formatted by the server (DashboardView::stats). */
export interface DashboardStat {
    key: string;
    label: string;
    value: string;
    /** DASH-1: one short sentence-case line from the server. */
    description: string;
    /** Only where the case list already has a matching filter. */
    href: string | null;
}

/** Display only: an icon per tile key. Unknown keys get no icon. */
const STAT_ICONS: Record<string, LucideIcon> = {
    needs_action: Inbox,
    open: FolderOpen,
    awaiting_hardcopy: FileClock,
    ready_for_file: FileCheck,
};

const GROUPING = new Intl.NumberFormat('en-US');

/** The server's formatted value ("1,234") as a number to count to, or null when it is not a plain count. */
function parseCount(value: string): number | null {
    const n = Number(value.replace(/,/g, ''));
    return /^[\d,]+$/.test(value) && Number.isFinite(n) ? n : null;
}

function Tile({ stat }: { stat: DashboardStat }) {
    const target = parseCount(stat.value);
    const shown = useCountUp(target);
    const Icon = STAT_ICONS[stat.key];
    const text = target === null || shown === null ? stat.value : GROUPING.format(shown);

    const body = (
        <>
            {Icon && (
                <div className="stat-figure text-base-content/40">
                    <Icon className="size-6" aria-hidden="true" />
                </div>
            )}
            <div className="stat-title text-sm capitalize">{stat.label}</div>
            <div className="stat-value text-3xl font-bold tabular-nums">{text}</div>
            <div className="stat-desc truncate" title={stat.description}>
                {stat.description}
            </div>
        </>
    );

    return (
        <div className="stats bg-base-100 border-base-300 min-w-0 border shadow-xs transition-shadow duration-200 hover:shadow-md">
            {stat.href ? (
                <Link
                    href={stat.href}
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

/** The stat row (Blueprint stats, Flowbite look): one white card per tile, chosen and worded by the server; values count up on load. */
export function KpiBand({ stats }: { stats: DashboardStat[] }) {
    return (
        <section aria-label="Summary" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {stats.map((stat) => (
                <Tile key={stat.key} stat={stat} />
            ))}
        </section>
    );
}
