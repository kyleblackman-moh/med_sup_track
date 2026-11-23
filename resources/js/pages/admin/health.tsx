import { FailedLoginsChart } from '@/components/admin/health/failed-logins-chart';
import { CheckTile, HealthGroup } from '@/components/admin/health/health-cards';
import {
    type AuditSeal,
    type ClamavCheck,
    type DiskCheck,
    type FailedJob,
    type FailedLoginDay,
    type HealthCard,
} from '@/components/admin/health/types';
import { FailedJobsTable } from '@/components/admin/jobs/failed-jobs-table';
import { RISE } from '@/components/dashboard/motion';
import { PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import { DataTableCard } from '@/components/ui/data-table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CircleAlert, Cog, HardDrive, ListX, ShieldAlert, ShieldCheck, Stamp, TriangleAlert, Users } from 'lucide-react';

interface Props {
    /** Already Port of Spain ("22 Nov 2026, 9:14 am"); the numbers are cached up to 60 seconds. */
    generated_at: string;
    users: HealthCard[];
    security: HealthCard[];
    operations: HealthCard[];
    clamav: ClamavCheck;
    disk: DiskCheck;
    audit_seal: AuditSeal;
    failed_logins_by_day: FailedLoginDay[];
    failed_jobs: FailedJob[];
    abilities: { retry_jobs: boolean };
}

function diskValue(disk: DiskCheck): string {
    if (disk.free_gb === null) return 'Unknown';
    return disk.total_gb === null ? `${disk.free_gb} GB free` : `${disk.free_gb} of ${disk.total_gb} GB free`;
}

/**
 * IT-ADMIN Part 3: the IT health page, the console home. Exceptions first: a
 * line naming every card the server marked warning or error, then Security
 * (last 24 hours against the week before), Users and Operations, each card
 * linking to the list behind its number; then the failed sign-ins chart and
 * the newest failed jobs. Every number and tone comes from the server.
 */
export default function AdminHealth(props: Props) {
    const {
        generated_at: generatedAt,
        users,
        security,
        operations,
        clamav,
        disk,
        audit_seal: seal,
        failed_logins_by_day: days,
        failed_jobs: jobs,
    } = props;
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'IT Health', href: route('admin.dashboard') }];

    const flagged = [...security, ...users, ...operations].filter((c) => c.tone === 'warning' || c.tone === 'error');
    const checks = [
        clamav.tone === 'error' || clamav.tone === 'warning' ? `ClamAV: ${clamav.label}` : null,
        disk.warning ? (disk.free_gb === null ? 'Disk space: could not be read' : `Disk space: ${disk.free_gb} GB free`) : null,
    ].filter((c): c is string => c !== null);
    const anyError = flagged.some((c) => c.tone === 'error') || clamav.tone === 'error';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="IT Health" />
            <div className="flex w-full min-w-0 flex-1 flex-col gap-6 px-4 py-4 lg:px-6">
                <PageHeader
                    className={RISE[0]}
                    titleClassName="capitalize"
                    title="IT Health"
                    subtitle={`Accounts, sign-in security and the systems behind them. Updated ${generatedAt}, Port of Spain; numbers refresh every minute.`}
                />

                <FlashMessages />

                <div className={RISE[1]}>
                    {flagged.length + checks.length > 0 ? (
                        <div
                            role="status"
                            className={anyError ? 'alert alert-soft alert-error items-start' : 'alert alert-soft alert-warning items-start'}
                        >
                            {anyError ? (
                                <CircleAlert className="size-5" aria-hidden="true" />
                            ) : (
                                <TriangleAlert className="size-5" aria-hidden="true" />
                            )}
                            <div className="grid min-w-0 gap-1 text-sm">
                                <h2 className="font-semibold capitalize">
                                    {flagged.length + checks.length === 1
                                        ? '1 Item Needs A Look'
                                        : `${flagged.length + checks.length} Items Need A Look`}
                                </h2>
                                <ul className="flex flex-wrap gap-x-4 gap-y-1">
                                    {flagged.map((c) => (
                                        <li key={c.key}>
                                            {c.href ? (
                                                <Link href={c.href} className="link">
                                                    {c.label}: <span className="tabular-nums">{c.value ?? 'unknown'}</span>
                                                </Link>
                                            ) : (
                                                <>
                                                    {c.label}: <span className="tabular-nums">{c.value ?? 'unknown'}</span>
                                                </>
                                            )}
                                        </li>
                                    ))}
                                    {checks.map((c) => (
                                        <li key={c}>{c}</li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    ) : (
                        <div role="status" className="alert alert-soft alert-success">
                            <ShieldCheck className="size-5" aria-hidden="true" />
                            <span className="text-sm">Nothing needs a look: no warnings on accounts, sign-ins or systems.</span>
                        </div>
                    )}
                </div>

                <div className={RISE[2]}>
                    <div className="space-y-6">
                        <HealthGroup
                            id="health-security"
                            title="Security"
                            description="The last 24 hours, against the daily average of the 7 days before."
                            icon={ShieldAlert}
                            cards={security}
                        />

                        <HealthGroup
                            id="health-users"
                            title="Users"
                            description="Accounts by state. Each number opens the filtered Users list."
                            icon={Users}
                            cards={users}
                        />

                        <HealthGroup
                            id="health-operations"
                            title="Operations"
                            description="Queues, email, uploads and the checks the system depends on."
                            icon={Cog}
                            cards={operations}
                        >
                            <CheckTile
                                label="Virus Scanner (ClamAV)"
                                value={clamav.label}
                                detail={clamav.host ? `Checked ${clamav.host} with a ping.` : 'Uploads are checked by the development scanner.'}
                                tone={clamav.tone}
                                icon={ShieldCheck}
                            />
                            <CheckTile
                                label="Documents Disk"
                                value={diskValue(disk)}
                                detail={
                                    disk.free_gb === null
                                        ? 'Free space could not be read.'
                                        : disk.warning
                                          ? `Below the ${disk.warn_below_gb} GB warning level.`
                                          : `Warns below ${disk.warn_below_gb} GB free.`
                                }
                                tone={disk.tone}
                                icon={HardDrive}
                            />
                        </HealthGroup>

                        <section aria-labelledby="health-audit" className="min-w-0 space-y-3">
                            <div>
                                <h2 id="health-audit" className="flex items-center gap-2 text-base font-semibold capitalize">
                                    <Stamp className="text-base-content/50 size-4" aria-hidden="true" />
                                    Audit Seal
                                </h2>
                                <p className="text-base-content/70 text-helper">Whether the audit log is sealed against later changes.</p>
                            </div>
                            <div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                <CheckTile
                                    label="Audit Log Seal"
                                    value={seal.label}
                                    detail={
                                        seal.configured ? 'The audit log is sealed.' : 'No sealing is set up. The audit log is still insert-only.'
                                    }
                                    tone={seal.tone}
                                    icon={Stamp}
                                />
                            </div>
                        </section>
                    </div>
                </div>

                <div className={RISE[5]}>
                    <FailedLoginsChart days={days} />

                    <section aria-labelledby="health-jobs" className="min-w-0">
                        <DataTableCard>
                            <div className="flex flex-wrap items-center justify-between gap-2 px-4 pt-4 pb-3 sm:px-6">
                                <div className="min-w-0">
                                    <h2 id="health-jobs" className="flex items-center gap-2 text-base font-semibold capitalize">
                                        <ListX className="text-base-content/50 size-4" aria-hidden="true" />
                                        Newest Failed Jobs
                                    </h2>
                                    <p className="text-base-content/70 text-helper">Job and exception class only.</p>
                                </div>
                                <Link href={route('admin.jobs.index')} className="btn btn-ghost btn-sm capitalize">
                                    All Failed Jobs
                                    <ArrowRight className="size-4" aria-hidden="true" />
                                </Link>
                            </div>
                            <FailedJobsTable jobs={jobs} canRetry={props.abilities.retry_jobs} caption="Newest failed queue jobs" />
                        </DataTableCard>
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
