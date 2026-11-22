import HeadingSmall from '@/components/heading-small';
import { ConfirmActionDialog } from '@/components/two-factor/confirm-action-dialog';
import { RecoveryCodeActions, RecoveryCodeList } from '@/components/two-factor/recovery-codes';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CircleCheck, Lock, RefreshCw, ShieldCheck, ShieldOff, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

type MfaState = 'off' | 'pending' | 'on';

interface SecurityProps {
    mfa: {
        state: MfaState;
        /** Already formatted in America/Port_of_Spain by the server. */
        confirmed_at: string | null;
        /** Mandatory for this account: it cannot be turned off. */
        required: boolean;
    };
    /** Only straight after regenerating: shown once. */
    recoveryCodes: string[] | null;
    /** Fortify's flashed status code, if any. */
    status: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Security Settings', href: '/settings/security' }];

const STATE_LABEL: Record<MfaState, string> = { on: 'On', off: 'Off', pending: 'Setup not finished' };
const STATE_STATUS: Record<MfaState, string> = { on: 'status-success', off: 'status-error', pending: 'status-warning' };

type PendingAction = 'regenerate' | 'disable' | null;

/**
 * Settings > Security (Blueprint pages/security-settings): the two-factor
 * card leads with its status (dot plus words, never colour alone) and the date
 * it was turned on, then ends in its footer row. Regenerating recovery codes
 * and turning MFA off each go through a confirmation that says plainly it
 * cannot be undone. Turn Off appears only when the server says MFA is optional
 * for this account; the endpoint refuses it otherwise.
 */
export default function Security({ mfa, recoveryCodes, status }: SecurityProps) {
    const [pending, setPending] = useState<PendingAction>(null);
    const [processing, setProcessing] = useState(false);

    const run = (action: Exclude<PendingAction, null>) => {
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setPending(null);
            },
        };
        if (action === 'regenerate') {
            router.post(route('two-factor.regenerate-recovery-codes'), {}, options);
        } else {
            router.delete(route('two-factor.disable'), options);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Security Settings" />

            <SettingsLayout>
                {status === 'two-factor-authentication-disabled' && (
                    <div role="status" className="alert alert-success alert-soft text-sm">
                        <CircleCheck className="size-4 shrink-0" aria-hidden="true" />
                        <span>Two-factor authentication is off. Your authenticator app and recovery codes no longer work for this account.</span>
                    </div>
                )}

                <section className="card bg-base-100 border-base-300 border" aria-labelledby="mfa-heading">
                    <div className="card-body gap-4 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div id="mfa-heading">
                                <HeadingSmall
                                    title="Two-Factor Authentication"
                                    description="A 6-digit code from an authenticator app, asked for after your password."
                                />
                            </div>
                            <span className="badge badge-soft badge-sm gap-1.5 capitalize">
                                <span className={cn('status status-sm', STATE_STATUS[mfa.state])} aria-hidden="true" />
                                {STATE_LABEL[mfa.state]}
                            </span>
                        </div>

                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_minmax(0,1fr)]">
                            <dt className="text-base-content/70 capitalize">Status</dt>
                            <dd>{STATE_LABEL[mfa.state]}</dd>
                            <dt className="text-base-content/70 capitalize">Turned On</dt>
                            <dd>{mfa.confirmed_at ?? 'Not yet'}</dd>
                            <dt className="text-base-content/70 capitalize">Recovery Codes</dt>
                            <dd>{mfa.state === 'on' ? '8 one-time codes, kept by you' : 'Made when setup is finished'}</dd>
                        </dl>

                        {mfa.required ? (
                            <p className="text-helper text-base-content/70 flex items-start gap-1.5">
                                <Lock className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                Required for your account by Ministry policy. It cannot be turned off.
                            </p>
                        ) : (
                            <p className="text-helper text-base-content/70">
                                Optional for your account at the moment. The Ministry recommends keeping it on.
                            </p>
                        )}

                        <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 flex-wrap items-center justify-end border-t px-4 pt-3">
                            {mfa.state === 'on' ? (
                                <>
                                    {!mfa.required && (
                                        <button type="button" className="btn btn-sm capitalize" onClick={() => setPending('disable')}>
                                            <ShieldOff className="size-4" aria-hidden="true" />
                                            Turn Off
                                        </button>
                                    )}
                                    <button type="button" className="btn btn-primary btn-sm capitalize" onClick={() => setPending('regenerate')}>
                                        <RefreshCw className="size-4" aria-hidden="true" />
                                        Regenerate Recovery Codes
                                    </button>
                                </>
                            ) : (
                                <Link href={route('two-factor.setup')} className="btn btn-primary btn-sm capitalize">
                                    <ShieldCheck className="size-4" aria-hidden="true" />
                                    {mfa.state === 'pending' ? 'Finish Setup' : 'Set Up'}
                                </Link>
                            )}
                        </div>
                    </div>
                </section>

                {recoveryCodes && (
                    <section className="card bg-base-100 border-base-300 border" aria-labelledby="codes-heading">
                        <div className="card-body gap-4 p-4">
                            <div id="codes-heading">
                                <HeadingSmall title="New Recovery Codes" description="Save these now. They are shown only once." />
                            </div>
                            <div role="alert" className="alert alert-warning alert-soft text-sm">
                                <TriangleAlert className="size-4 shrink-0" aria-hidden="true" />
                                <span>Your previous recovery codes no longer work. Replace any copy you kept.</span>
                            </div>
                            <RecoveryCodeList codes={recoveryCodes} />
                            <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 flex-wrap items-center justify-end border-t px-4 pt-3">
                                <RecoveryCodeActions codes={recoveryCodes} />
                            </div>
                        </div>
                    </section>
                )}

                <ConfirmActionDialog
                    open={pending === 'regenerate'}
                    onClose={() => setPending(null)}
                    onConfirm={() => run('regenerate')}
                    processing={processing}
                    title="Regenerate recovery codes?"
                    description="You will get 8 new codes, and every code you saved before stops working straight away."
                    confirmLabel="Regenerate Codes"
                    icon={RefreshCw}
                />
                <ConfirmActionDialog
                    open={pending === 'disable'}
                    onClose={() => setPending(null)}
                    onConfirm={() => run('disable')}
                    processing={processing}
                    title="Turn off two-factor authentication?"
                    description="Your authenticator app and all your recovery codes stop working for this account, and signing in will need only your password. To turn it on again you will scan a new QR code."
                    confirmLabel="Turn Off"
                    icon={ShieldOff}
                    destructive
                />
            </SettingsLayout>
        </AppLayout>
    );
}
