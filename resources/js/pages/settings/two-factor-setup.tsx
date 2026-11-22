import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { CodeInput } from '@/components/two-factor/code-input';
import { RecoveryCodeActions, RecoveryCodeList } from '@/components/two-factor/recovery-codes';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Info, QrCode, RotateCcw, ShieldCheck, Smartphone } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type SetupState = 'off' | 'pending' | 'on';

interface TwoFactorSetupProps {
    state: SetupState;
    /** Fortify's inline SVG of the otpauth:// QR code, only while setup is pending. */
    qrCodeSvg: string | null;
    /** The same secret as text, for typing into the app by hand. */
    secretKey: string | null;
    /** Only straight after the code is confirmed: shown once. */
    recoveryCodes: string[] | null;
    required: boolean;
    continueUrl: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Security Settings', href: '/settings/security' },
    { title: 'Two-Factor Setup', href: '/settings/two-factor' },
];

/** The secret in groups of four, easier to copy by eye. */
const groupSecret = (secret: string) => secret.match(/.{1,4}/g)?.join(' ') ?? secret;

/**
 * MFA setup (Blueprint pages/security-settings with the onboarding step
 * list): a three-step key ceremony. 1. Scan the QR code (or type the secret),
 * 2. confirm with a 6-digit code, 3. keep the recovery codes (shown once, with
 * Copy and Download) and say they are saved before continuing. Every write is
 * Fortify's own endpoint; the page only reflects the state the server sends.
 */
export default function TwoFactorSetup({ state, qrCodeSvg, secretKey, recoveryCodes, required, continueUrl }: TwoFactorSetupProps) {
    const step = state === 'off' || state === 'pending' ? (state === 'off' ? 0 : 1) : 2;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-Factor Setup" />

            <SettingsLayout>
                {required && state !== 'on' && (
                    <div role="alert" className="alert alert-info alert-soft text-sm">
                        <Info className="size-4 shrink-0" aria-hidden="true" />
                        <span>Two-factor authentication is required for your account. Finish these steps to open the rest of the system.</span>
                    </div>
                )}

                <ul className="steps steps-vertical sm:steps-horizontal bg-base-100 border-base-300 rounded-box w-full border px-2 py-1 text-sm">
                    <li className={cn('step capitalize', step >= 0 && 'step-primary')}>Scan the QR Code</li>
                    <li className={cn('step capitalize', step >= 1 && 'step-primary')}>Enter the Code</li>
                    <li className={cn('step capitalize', step >= 2 && 'step-primary')}>Keep Your Recovery Codes</li>
                </ul>

                {state === 'off' && <StartCard required={required} />}
                {state === 'pending' && qrCodeSvg && secretKey && <ScanAndConfirmCard qrCodeSvg={qrCodeSvg} secretKey={secretKey} />}
                {state === 'on' && recoveryCodes && <SaveCodesCard codes={recoveryCodes} continueUrl={continueUrl} />}
                {state === 'on' && !recoveryCodes && <AlreadyOnCard continueUrl={continueUrl} />}
            </SettingsLayout>
        </AppLayout>
    );
}

function StartCard({ required }: { required: boolean }) {
    const [processing, setProcessing] = useState(false);

    const start = () =>
        router.post(
            route('two-factor.enable'),
            {},
            { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );

    return (
        <section className="card bg-base-100 border-base-300 border">
            <div className="card-body gap-4 p-4">
                <HeadingSmall
                    title="Set Up Your Authenticator App"
                    description="Each time you sign in you will enter your password and a 6-digit code from an app on your phone."
                />
                <ul className="text-base-content/80 grid gap-2 text-sm">
                    <li className="flex items-start gap-2">
                        <Smartphone className="text-base-content/60 mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        Install an authenticator app if you do not have one, such as Microsoft Authenticator or Google Authenticator.
                    </li>
                    <li className="flex items-start gap-2">
                        <QrCode className="text-base-content/60 mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        Next you will scan a QR code with the app, then type the code it shows to confirm.
                    </li>
                </ul>
                <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 items-center justify-end border-t px-4 pt-3">
                    {!required && (
                        <Link href={route('security.edit')} className="btn btn-sm capitalize">
                            Back to Security
                        </Link>
                    )}
                    <button
                        type="button"
                        className="btn btn-primary btn-sm capitalize"
                        onClick={start}
                        disabled={processing}
                        aria-busy={processing || undefined}
                    >
                        {processing ? (
                            <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                        ) : (
                            <ShieldCheck className="size-4" aria-hidden="true" />
                        )}
                        Begin Setup
                    </button>
                </div>
            </div>
        </section>
    );
}

function ScanAndConfirmCard({ qrCodeSvg, secretKey }: { qrCodeSvg: string; secretKey: string }) {
    const [restarting, setRestarting] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const confirm: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('two-factor.confirm'), {
            errorBag: 'confirmTwoFactorAuthentication',
            preserveScroll: true,
            onError: () => reset('code'),
        });
    };

    // A new secret: the QR code already scanned stops working.
    const restart = () =>
        router.post(
            route('two-factor.enable'),
            { force: true },
            { preserveScroll: true, onStart: () => setRestarting(true), onFinish: () => setRestarting(false) },
        );

    return (
        <form onSubmit={confirm} className="card bg-base-100 border-base-300 border" noValidate>
            <div className="card-body gap-4 p-4">
                <HeadingSmall
                    title="Scan and Confirm"
                    description="Add this account to your authenticator app, then enter the 6-digit code the app shows."
                />

                <div className="grid gap-5 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-start">
                    <figure className="bg-base-100 border-base-300 rounded-box size-52 self-start border p-2">
                        <div
                            role="img"
                            aria-label="QR code for your authenticator app"
                            className="size-48 [&>svg]:size-full"
                            dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
                        />
                    </figure>

                    <fieldset className="fieldset min-w-0 gap-2 py-0" disabled={processing || restarting}>
                        <p className="label text-base-content text-sm font-medium capitalize">Can&apos;t Scan? Enter This Key</p>
                        <p className="bg-base-200 border-base-300 rounded-field border px-3 py-2 font-mono text-sm tracking-wider break-all">
                            {groupSecret(secretKey)}
                        </p>
                        <p className="text-helper text-base-content/70">Choose a time-based key in the app. Spaces do not matter.</p>

                        <label htmlFor="code" className="label text-base-content mt-3 text-sm font-medium capitalize">
                            6-Digit Code
                        </label>
                        <CodeInput
                            id="code"
                            value={data.code}
                            onChange={(value) => setData('code', value)}
                            invalid={!!errors.code}
                            describedBy={errors.code ? 'code-error' : 'code-hint'}
                        />
                        {errors.code ? (
                            <InputError id="code-error" message={errors.code} />
                        ) : (
                            <p id="code-hint" className="text-helper text-base-content/70">
                                The code changes every 30 seconds.
                            </p>
                        )}
                    </fieldset>
                </div>

                <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 items-center justify-end border-t px-4 pt-3">
                    <button type="button" className="btn btn-sm capitalize" onClick={restart} disabled={processing || restarting}>
                        {restarting ? (
                            <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                        ) : (
                            <RotateCcw className="size-4" aria-hidden="true" />
                        )}
                        Start Again
                    </button>
                    <button
                        type="submit"
                        className="btn btn-primary btn-sm capitalize"
                        disabled={processing || restarting || data.code.length !== 6}
                        aria-busy={processing || undefined}
                    >
                        {processing ? (
                            <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                        ) : (
                            <ShieldCheck className="size-4" aria-hidden="true" />
                        )}
                        Confirm Code
                    </button>
                </div>
            </div>
        </form>
    );
}

function SaveCodesCard({ codes, continueUrl }: { codes: string[]; continueUrl: string }) {
    const [saved, setSaved] = useState(false);

    return (
        <section className="card bg-base-100 border-base-300 border">
            <div className="card-body gap-4 p-4">
                <HeadingSmall
                    title="Keep Your Recovery Codes"
                    description={`Two-factor authentication is on. If you lose your phone, each of these ${codes.length} codes signs you in once. They are shown only now.`}
                />
                <RecoveryCodeList codes={codes} />
                <label className="label text-base-content cursor-pointer gap-2 text-sm whitespace-normal">
                    <input type="checkbox" className="checkbox checkbox-sm" checked={saved} onChange={(e) => setSaved(e.target.checked)} />I have
                    saved my recovery codes somewhere safe
                </label>
                <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 flex-wrap items-center justify-end border-t px-4 pt-3">
                    <RecoveryCodeActions codes={codes} />
                    <Link
                        href={continueUrl}
                        className={cn('btn btn-primary btn-sm capitalize', !saved && 'btn-disabled')}
                        aria-disabled={!saved || undefined}
                        tabIndex={saved ? undefined : -1}
                        onClick={(e) => !saved && e.preventDefault()}
                    >
                        Continue
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </section>
    );
}

function AlreadyOnCard({ continueUrl }: { continueUrl: string }) {
    return (
        <section className="card bg-base-100 border-base-300 border">
            <div className="card-body gap-4 p-4">
                <HeadingSmall
                    title="Two-Factor Authentication Is On"
                    description="Your authenticator app is set up. Manage recovery codes from Security settings."
                />
                <div className="card-actions border-base-300 -mx-4 mt-1 -mb-1 items-center justify-end border-t px-4 pt-3">
                    <Link href={route('security.edit')} className="btn btn-sm capitalize">
                        Go to Security
                    </Link>
                    <Link href={continueUrl} className="btn btn-primary btn-sm capitalize">
                        Continue
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </section>
    );
}
