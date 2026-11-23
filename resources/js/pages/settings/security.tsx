import HeadingSmall from '@/components/heading-small';
import { ConfirmActionDialog } from '@/components/two-factor/confirm-action-dialog';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Lock, RefreshCw, ShieldCheck, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

interface SecurityProps {
    mfa: {
        enrolled: boolean;
        /** UTC ISO 8601; shown in America/Port_of_Spain. */
        confirmed_at: string | null;
        recovery_codes_left: number;
    };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Security Settings', href: '/settings/security' }];

/** At or below this many unused codes, the page suggests making new ones. */
const LOW_CODES = 2;

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-TT', { timeZone: 'America/Port_of_Spain', dateStyle: 'long', timeStyle: 'short' });
}

/**
 * Settings > Security (Blueprint pages/security-settings): one card for the
 * authenticator. It leads with the status (dot plus words, never colour
 * alone), when it was set up and how many recovery codes are left, then ends
 * in its footer row. There is no turn-off: MFA is mandatory and only an
 * administrator can reset it. Regenerating recovery codes goes through a
 * confirmation that says it cannot be undone (the server may ask for the
 * password first), then the new codes page.
 */
export default function Security({ mfa }: SecurityProps) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const low = mfa.enrolled && mfa.recovery_codes_left <= LOW_CODES;

    const regenerate = () =>
        router.post(
            route('security.mfa.regenerate'),
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setConfirming(false);
                },
            },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Security Settings" />

            <SettingsLayout>
                <section className="card bg-base-100 border-base-300 border" aria-labelledby="mfa-heading">
                    <div className="card-body gap-4 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div id="mfa-heading">
                                <HeadingSmall
                                    title="Multi-Factor Authentication"
                                    description="A 6-digit code from an authenticator app, asked for after your password."
                                />
                            </div>
                            <span className="badge badge-soft badge-sm gap-1.5 capitalize">
                                <span className={cn('status status-sm', mfa.enrolled ? 'status-success' : 'status-warning')} aria-hidden="true" />
                                {mfa.enrolled ? 'On' : 'Not set up'}
                            </span>
                        </div>

                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_minmax(0,1fr)]">
                            <dt className="text-base-content/70 capitalize">Set Up</dt>
                            <dd className="tabular-nums">{mfa.confirmed_at ? formatDateTime(mfa.confirmed_at) : 'Not yet'}</dd>
                            <dt className="text-base-content/70 capitalize">Recovery Codes</dt>
                            <dd className="tabular-nums">
                                {mfa.enrolled
                                    ? `${mfa.recovery_codes_left} unused ${mfa.recovery_codes_left === 1 ? 'code' : 'codes'}, kept by you`
                                    : 'Made when setup is finished'}
                            </dd>
                        </dl>

                        {low && (
                            <div role="alert" className="alert alert-warning alert-soft items-start text-sm">
                                <TriangleAlert className="size-4 shrink-0" aria-hidden="true" />
                                <span>
                                    {mfa.recovery_codes_left === 0
                                        ? 'You have no recovery codes left. Make new ones so you can still sign in if you lose your phone.'
                                        : 'You are running out of recovery codes. Make new ones so you can still sign in if you lose your phone.'}
                                </span>
                            </div>
                        )}

                        <p className="text-helper text-base-content/70 flex items-start gap-1.5">
                            <Lock className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            Required for every account by Ministry policy, so it cannot be turned off. If you lose your phone and your recovery codes,
                            ask your administrator to reset it.
                        </p>

                        <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 flex-wrap items-center justify-end border-t px-4 pt-3">
                            {mfa.enrolled ? (
                                <button
                                    type="button"
                                    className={cn('btn btn-sm capitalize', low && 'btn-primary')}
                                    onClick={() => setConfirming(true)}
                                >
                                    <RefreshCw className="size-4" aria-hidden="true" />
                                    Regenerate Recovery Codes
                                </button>
                            ) : (
                                <Link href={route('security.mfa.setup')} className="btn btn-primary btn-sm capitalize">
                                    <ShieldCheck className="size-4" aria-hidden="true" />
                                    Set Up
                                </Link>
                            )}
                        </div>
                    </div>
                </section>

                <ConfirmActionDialog
                    open={confirming}
                    onClose={() => setConfirming(false)}
                    onConfirm={regenerate}
                    processing={processing}
                    title="Regenerate recovery codes?"
                    description="You will get a new set of codes, and every code you saved before stops working straight away. You may be asked for your password first."
                    confirmLabel="Regenerate Codes"
                    icon={RefreshCw}
                />
            </SettingsLayout>
        </AppLayout>
    );
}
