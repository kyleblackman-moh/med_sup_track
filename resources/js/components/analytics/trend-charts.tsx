import { prefersReducedMotion, useMounted } from '@/components/dashboard/motion';
import { EmptyState } from '@/components/ui/empty-state';
import { Link } from '@inertiajs/react';
import type { ApexOptions } from 'apexcharts';
import { ArrowRight, ChartColumn, TrendingUp } from 'lucide-react';
import { lazy, Suspense, useMemo, type ReactNode } from 'react';

/** ApexCharts touches `window` on import: load it on the client only, after mount. */
const ApexChart = lazy(() => import('react-apexcharts'));

/** AnalyticsCharts::weekly: twelve week labels and a count per week. */
export interface WeeklySeries {
    labels: string[];
    received: number[];
    granted: number[];
}

/** AnalyticsCharts::outcomes: month labels and the closing outcomes per month. */
export interface OutcomeSeries {
    labels: string[];
    granted: number[];
    rejected: number[];
    withdrawn: number[];
}

const sum = (values: number[]) => values.reduce((total, v) => total + v, 0);

/** A daisyUI theme colour as the browser resolved it (charts draw SVG attributes, which cannot take var()). */
function token(name: string): string {
    try {
        const value = getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim();
        return value || `var(--color-${name})`;
    } catch {
        return `var(--color-${name})`;
    }
}

/** Shared chart options: theme colours read at runtime, no toolbar, quiet grid, motion only when allowed. */
function baseOptions(): ApexOptions {
    return {
        chart: {
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'inherit',
            foreColor: token('base-content'),
            parentHeightOffset: 0,
            animations: { enabled: !prefersReducedMotion(), speed: 500 },
        },
        dataLabels: { enabled: false },
        grid: { borderColor: token('base-300'), strokeDashArray: 4 },
        legend: { position: 'top', horizontalAlign: 'right', fontSize: '13px' },
        yaxis: { min: 0, forceNiceScale: true, labels: { formatter: (v: number) => String(Math.round(v)) } },
        tooltip: { shared: true, intersect: false },
    };
}

/** The chart slot: a shape-matched skeleton on the server and while ApexCharts loads, then the chart. */
function ChartSlot({ label, height, children }: { label: string; height: string; children: () => ReactNode }) {
    const mounted = useMounted();
    const skeleton = <div className={height === 'tall' ? 'skeleton h-72 w-full' : 'skeleton h-64 w-full'} />;

    return (
        <div role="img" aria-label={label} className={height === 'tall' ? 'min-h-72 min-w-0' : 'min-h-64 min-w-0'}>
            {mounted ? <Suspense fallback={skeleton}>{children()}</Suspense> : skeleton}
        </div>
    );
}

/** The numbers behind a chart, for screen readers (the chart itself is one image). */
function DataTableSummary({ caption, headers, rows }: { caption: string; headers: string[]; rows: (string | number)[][] }) {
    return (
        <div className="sr-only overflow-x-auto">
            <table>
                <caption>{caption}</caption>
                <thead>
                    <tr>
                        {headers.map((h) => (
                            <th key={h} scope="col">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={String(row[0])}>
                            {row.map((cell, i) =>
                                i === 0 ? (
                                    <th key={i} scope="row">
                                        {cell}
                                    </th>
                                ) : (
                                    <td key={i}>{cell}</td>
                                ),
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * "Cases Received And Granted": the wide area chart (Flowbite look). Received
 * is the one primary series; granted uses the success token. Empty weeks show
 * an empty state, never a flat chart.
 */
export function WeeklyTrendCard({ weekly, casesHref }: { weekly: WeeklySeries; casesHref: string | null }) {
    const received = sum(weekly.received);
    const granted = sum(weekly.granted);
    const empty = weekly.labels.length === 0 || received + granted === 0;
    const weeks = weekly.labels.length;

    const options = useMemo<ApexOptions>(() => {
        if (empty) return {};
        const base = baseOptions();
        return {
            ...base,
            colors: [token('primary'), token('success')],
            stroke: { width: 3, curve: 'smooth' },
            fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0 } },
            xaxis: { categories: weekly.labels, axisBorder: { show: false }, axisTicks: { show: false } },
        };
    }, [empty, weekly.labels]);

    return (
        <section
            aria-labelledby="weekly-trend-heading"
            className="card bg-base-100 border-base-300 min-w-0 border shadow-xs transition-shadow duration-200 hover:shadow-md xl:col-span-2"
        >
            <div className="card-body gap-2 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 id="weekly-trend-heading" className="flex items-center gap-2 text-lg font-semibold capitalize">
                            <TrendingUp className="text-base-content/50 size-5" aria-hidden="true" />
                            Cases Received And Granted
                        </h2>
                        <p className="text-base-content/60 text-sm">Cases submitted and non-objections granted each week</p>
                    </div>
                    <dl className="flex gap-6 text-right">
                        <div>
                            <dt className="text-base-content/60 text-xs capitalize">Received</dt>
                            <dd className="text-2xl leading-tight font-bold tabular-nums">{received}</dd>
                        </div>
                        <div>
                            <dt className="text-base-content/60 text-xs capitalize">Granted</dt>
                            <dd className="text-2xl leading-tight font-bold tabular-nums">{granted}</dd>
                        </div>
                    </dl>
                </div>

                {empty ? (
                    <EmptyState message={`No cases were received or granted in the last ${weeks || 12} weeks.`} />
                ) : (
                    <>
                        <ChartSlot
                            height="tall"
                            label={`Area chart: ${received} cases received and ${granted} granted over the last ${weeks} weeks, ${weekly.labels[0]} to ${weekly.labels[weeks - 1]}.`}
                        >
                            {() => (
                                <ApexChart
                                    type="area"
                                    height={288}
                                    options={options}
                                    series={[
                                        { name: 'Received', data: weekly.received },
                                        { name: 'Granted', data: weekly.granted },
                                    ]}
                                />
                            )}
                        </ChartSlot>
                        <DataTableSummary
                            caption="Cases received and granted per week"
                            headers={['Week of', 'Received', 'Granted']}
                            rows={weekly.labels.map((label, i) => [label, weekly.received[i] ?? 0, weekly.granted[i] ?? 0])}
                        />
                    </>
                )}

                <div className="border-base-300 mt-2 flex items-center justify-between gap-2 border-t pt-3">
                    <span className="text-base-content/60 text-sm">Last {weeks || 12} weeks</span>
                    {casesHref && (
                        <Link href={casesHref} className="btn btn-ghost btn-sm capitalize transition-transform active:scale-[0.97]">
                            File Tracking
                            <ArrowRight className="size-4" aria-hidden="true" />
                        </Link>
                    )}
                </div>
            </div>
        </section>
    );
}

/** "Outcomes By Month": grouped bars per month, one semantic token per outcome; the legend names each colour. */
export function OutcomesCard({ outcomes }: { outcomes: OutcomeSeries }) {
    const totals = { granted: sum(outcomes.granted), rejected: sum(outcomes.rejected), withdrawn: sum(outcomes.withdrawn) };
    const months = outcomes.labels.length;
    const empty = months === 0 || totals.granted + totals.rejected + totals.withdrawn === 0;

    const options = useMemo<ApexOptions>(() => {
        if (empty) return {};
        const base = baseOptions();
        return {
            ...base,
            colors: [token('success'), token('error'), token('neutral')],
            plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } },
            xaxis: { categories: outcomes.labels, axisBorder: { show: false }, axisTicks: { show: false } },
        };
    }, [empty, outcomes.labels]);

    return (
        <section
            aria-labelledby="outcomes-heading"
            className="card bg-base-100 border-base-300 min-w-0 border shadow-xs transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body gap-2 p-4 sm:p-6">
                <h2 id="outcomes-heading" className="flex items-center gap-2 text-lg font-semibold capitalize">
                    <ChartColumn className="text-base-content/50 size-5" aria-hidden="true" />
                    Outcomes By Month
                </h2>
                <p className="text-base-content/60 text-sm">
                    {empty
                        ? 'Granted, rejected and withdrawn cases each month'
                        : `${totals.granted} granted, ${totals.rejected} rejected and ${totals.withdrawn} withdrawn in ${months} months`}
                </p>

                {empty ? (
                    <EmptyState message={`No cases were closed in the last ${months || 6} months.`} />
                ) : (
                    <>
                        <ChartSlot
                            height="short"
                            label={`Bar chart: ${totals.granted} granted, ${totals.rejected} rejected and ${totals.withdrawn} withdrawn, ${outcomes.labels[0]} to ${outcomes.labels[months - 1]}.`}
                        >
                            {() => (
                                <ApexChart
                                    type="bar"
                                    height={256}
                                    options={options}
                                    series={[
                                        { name: 'Granted', data: outcomes.granted },
                                        { name: 'Rejected', data: outcomes.rejected },
                                        { name: 'Withdrawn', data: outcomes.withdrawn },
                                    ]}
                                />
                            )}
                        </ChartSlot>
                        <DataTableSummary
                            caption="Case outcomes per month"
                            headers={['Month', 'Granted', 'Rejected', 'Withdrawn']}
                            rows={outcomes.labels.map((label, i) => [
                                label,
                                outcomes.granted[i] ?? 0,
                                outcomes.rejected[i] ?? 0,
                                outcomes.withdrawn[i] ?? 0,
                            ])}
                        />
                    </>
                )}
            </div>
        </section>
    );
}
