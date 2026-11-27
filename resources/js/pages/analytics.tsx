import { OutcomesCard, WeeklyTrendCard, type OutcomeSeries, type WeeklySeries } from '@/components/analytics/trend-charts';
import { KpiBand, type DashboardStat } from '@/components/dashboard/kpi-band';
import { RISE } from '@/components/dashboard/motion';
import { PipelinePanel, type GateTrackerData, type PipelineStage } from '@/components/dashboard/pipeline-panels';
import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface Props {
    /** Open, awaiting hardcopy, ready for file; worded by the server. Null when the user has none. */
    stats: DashboardStat[] | null;
    /** A count per stage in workflow order; null without the staff case list. */
    pipeline: PipelineStage[] | null;
    /** In Review cases and their gate items; null without case.view_all. */
    gateTracker: GateTrackerData | null;
    /** Twelve weeks of received and granted, and the closing outcomes per month. */
    charts: { weekly: WeeklySeries; outcomes: OutcomeSeries };
}

/**
 * Analytics (the CMO's office and the PS office; the route authorises): the
 * stat row, the weekly received/granted trend beside the monthly outcomes,
 * then Cases By Stage beside the Gate Tracker. Charts are client-only.
 */
export default function Analytics({ stats, pipeline, charts }: Props) {
    const { can } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Analytics', href: route('analytics') }];
    const casesHref = can?.cases ? route('cases.index') : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Analytics" />
            <div className="flex w-full min-w-0 flex-1 flex-col gap-4 px-4 py-4 lg:px-6">
                <PageHeader
                    className={RISE[0]}
                    titleClassName="capitalize"
                    title="Analytics"
                    subtitle="Case volumes, outcomes and the gate for every open case. Times are Port of Spain."
                />

                {stats && stats.length > 0 && (
                    <div className={RISE[1]}>
                        <KpiBand stats={stats} />
                    </div>
                )}

                <div className={RISE[4]}>
                    <WeeklyTrendCard weekly={charts.weekly} casesHref={casesHref} />
                    <OutcomesCard outcomes={charts.outcomes} />
                </div>

                {/* Kyle, 2026-11-26: the gate tracker is no longer shown. */}
                {pipeline && (
                    <div className={RISE[5]}>
                        <PipelinePanel stages={pipeline} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
