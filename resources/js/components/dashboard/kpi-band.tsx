import { useCountUp } from '@/components/dashboard/motion';
import { type DashboardKpi } from '@/types/dashboard';

function Tile({ kpi }: { kpi: DashboardKpi }) {
    const shown = useCountUp(kpi.value);
    let text = '—';
    if (shown !== null) {
        text = kpi.format === 'days' ? (kpi.value === 0 ? 'Today' : `${shown} ${kpi.value === 1 ? 'day' : 'days'}`) : String(shown);
    }

    return (
        <div className="stat min-w-0 px-4 py-3">
            <div className="stat-title capitalize">{kpi.label}</div>
            <div className="stat-value text-3xl tabular-nums">{text}</div>
            <div className="stat-desc truncate" title={kpi.hint}>
                {kpi.hint}
            </div>
        </div>
    );
}

/** The KPI band (Blueprint stats): the same shape for every role, tiles chosen by the server. */
export function KpiBand({ kpis }: { kpis: DashboardKpi[] }) {
    if (kpis.length === 0) return null;

    return (
        <section aria-label="Summary">
            <div className="stats stats-vertical sm:stats-horizontal bg-base-100 border-base-300 w-full border">
                {kpis.map((kpi) => (
                    <Tile key={kpi.key} kpi={kpi} />
                ))}
            </div>
        </section>
    );
}
