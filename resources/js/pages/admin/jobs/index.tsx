import { type FailedJob } from '@/components/admin/health/types';
import { FailedJobsTable } from '@/components/admin/jobs/failed-jobs-table';
import { PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import { DataTableCard } from '@/components/ui/data-table';
import { JoinPagination } from '@/components/ui/join-pagination';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Props {
    jobs: {
        data: FailedJob[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    abilities: { retry: boolean };
}

const PER_PAGE = 25; // FailedJobController::PER_PAGE

/**
 * IT-ADMIN Part 3: every failed queue job, newest first, 25 a page. Retry
 * (jobs.retry only) confirms in a dialog and asks for the password when it
 * was not confirmed in the last 15 minutes.
 */
export default function FailedJobsIndex({ jobs, abilities }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'IT Health', href: route('admin.dashboard') },
        { title: 'Failed Jobs', href: route('admin.jobs.index') },
    ];
    const from = jobs.total === 0 ? null : (jobs.current_page - 1) * PER_PAGE + 1;
    const to = jobs.total === 0 ? null : (from ?? 1) + jobs.data.length - 1;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Failed Jobs" />
            <div className="flex w-full min-w-0 flex-1 flex-col gap-4 px-4 py-4 lg:px-6">
                <PageHeader
                    titleClassName="capitalize"
                    title="Failed Jobs"
                    subtitle={
                        jobs.total === 0
                            ? 'Queue jobs that failed for good. Times are Port of Spain.'
                            : `${jobs.total} queue job${jobs.total === 1 ? '' : 's'} failed for good. Times are Port of Spain.`
                    }
                />

                <FlashMessages />

                <DataTableCard>
                    <FailedJobsTable jobs={jobs.data} canRetry={abilities.retry} caption="Failed queue jobs" />
                    {jobs.last_page > 1 && (
                        <JoinPagination
                            label="Failed job pages"
                            from={from}
                            to={to}
                            total={jobs.total}
                            prevUrl={jobs.prev_page_url}
                            nextUrl={jobs.next_page_url}
                        />
                    )}
                </DataTableCard>
            </div>
        </AppLayout>
    );
}
