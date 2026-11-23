import { type FailedLoginDay } from '@/components/admin/health/types';
import { prefersReducedMotion, useMounted } from '@/components/dashboard/motion';
import { EmptyState } from '@/components/ui/empty-state';
import type { ApexOptions } from 'apexcharts';
import { ChartColumn } from 'lucide-react';
import { lazy, Suspense, useMemo } from 'react';

/** ApexCharts touches `window` on import: client only, after mount (as on Analytics). */
const ApexChart = lazy(() => import('react-apexcharts'));

/** A daisyUI theme colour as the browser resolved it (SVG attributes cannot take var()). */
function token(name: string): string {
    try {
        const value = getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim();
        return value || `var(--color-${name})`;
    } catch {
        return `var(--color-${name})`;
    }
}

/**
 * IT-ADMIN Part 3: failed sign-ins per Port of Spain day over the last 14
 * days (the server sends every day, zero included). One series, the
 * base-content token; the numbers are also in a visually hidden list.
 */
export function FailedLoginsChart({ days }: { days: FailedLoginDay[] }) {
    const mounted = useMounted();
    const total = days.reduce((sum, d) => sum + d.count, 0);
    const peak = days.reduce<FailedLoginDay | null>((top, d) => (top === null || d.count > top.count ? d : top), null);
    const first = days[0]?.label;
    const last = days[days.length - 1]?.label;

    const options = useMemo<ApexOptions>(() => {
        if (!mounted || total === 0) return {};
        return {
            chart: {
                toolbar: { show: false },
                zoom: { enabled: false },
                fontFamily: 'inherit',
                foreColor: token('base-content'),
                parentHeightOffset: 0,
                animations: { enabled: !prefersReducedMotion(), speed: 400 },
            },
            colors: [token('base-content')],
            plotOptions: { bar: { borderRadius: 3, columnWidth: '55%' } },
            dataLabels: { enabled: false },
            grid: { borderColor: token('base-300'), strokeDashArray: 4 },
            xaxis: { categories: days.map((d) => d.label), axisBorder: { show: false }, axisTicks: { show: false } },
            yaxis: { min: 0, forceNiceScale: true, labels: { formatter: (v: number) => String(Math.round(v)) } },
            tooltip: { y: { formatter: (v: number) => `${v} failed sign-in${v === 1 ? '' : 's'}` } },
            legend: { show: false },
        };
    }, [mounted, total, days]);

    const skeleton = <div className="skeleton h-60 w-full" />;

    return (
        <section aria-labelledby="failed-logins-heading" className="card bg-base-100 border-base-300 min-w-0 border shadow-xs">
            <div className="card-body min-w-0 gap-3 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 id="failed-logins-heading" className="flex items-center gap-2 text-base font-semibold capitalize">
                            <ChartColumn className="text-base-content/50 size-4" aria-hidden="true" />
                            Failed Sign-Ins Per Day
                        </h2>
                        <p className="text-base-content/70 text-helper">
                            Last 14 days, Port of Spain time{first && last ? ` (${first} to ${last})` : ''}.
                        </p>
                    </div>
                    <dl className="flex gap-6 text-right">
                        <div>
                            <dt className="text-base-content/70 text-xs capitalize">Total</dt>
                            <dd className="text-2xl leading-tight font-bold tabular-nums">{total}</dd>
                        </div>
                        {peak && total > 0 && (
                            <div>
                                <dt className="text-base-content/70 text-xs capitalize">Busiest Day</dt>
                                <dd className="text-2xl leading-tight font-bold tabular-nums">
                                    {peak.count} <span className="text-base-content/70 text-sm font-normal">on {peak.label}</span>
                                </dd>
                            </div>
                        )}
                    </dl>
                </div>

                {total === 0 ? (
                    <EmptyState message="No failed sign-ins in the last 14 days." />
                ) : (
                    <>
                        <div role="img" aria-label={`Bar chart: ${total} failed sign-ins from ${first} to ${last}.`} className="min-h-60 min-w-0">
                            {mounted ? (
                                <Suspense fallback={skeleton}>
                                    <ApexChart
                                        type="bar"
                                        height={240}
                                        options={options}
                                        series={[{ name: 'Failed sign-ins', data: days.map((d) => d.count) }]}
                                    />
                                </Suspense>
                            ) : (
                                skeleton
                            )}
                        </div>
                        <ul className="sr-only" aria-label="Failed sign-ins per day">
                            {days.map((d) => (
                                <li key={d.date}>
                                    {d.label}: {d.count}
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </section>
    );
}
