import InputError from '@/components/input-error';
import { CodeInput } from '@/components/two-factor/code-input';
import EnrolmentLayout from '@/components/two-factor/enrolment-layout';
import { Head, useForm } from '@inertiajs/react';
import { ShieldCheck, Smartphone } from 'lucide-react';
import { FormEventHandler } from 'react';

interface MfaSetupProps {
    /** The server's inline SVG of the otpauth:// QR code. */
    qr_svg: string;
    /** The same secret as text, for typing into the app by hand. */
    secret: string;
    /** Example apps the server names. */
    apps: string[];
}

/** The secret in groups of four, easier to copy by eye. */
const groupSecret = (secret: string) => secret.match(/.{1,4}/g)?.join(' ') ?? secret;

/**
 * MFA-1 enrolment, step 2 of 3 (Blueprint pages/security-settings): scan the
 * QR code (or type the key) into an authenticator app, then confirm with the
 * 6-digit code it shows. The secret stays the same on reload until confirmed.
 * Required for every account; the server keeps the user here until it is done.
 */
export default function MfaSetup({ qr_svg: qrSvg, secret, apps }: MfaSetupProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const confirm: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('security.mfa.confirm'), { onError: () => reset('code') });
    };

    return (
        <EnrolmentLayout
            step={1}
            title="Pair Your Authenticator App"
            description="Every account needs a 6-digit code from an app on your phone, asked for after your password. This is required by Ministry policy."
        >
            <Head title="Set Up Authentication" />

            <p className="text-base-content/80 flex items-start gap-2 text-sm">
                <Smartphone className="text-base-content/60 mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                    Install an authenticator app if you do not have one{apps.length > 0 && <>, such as {apps.join(' or ')}</>}. In the app, add an
                    account and scan this code.
                </span>
            </p>

            <form onSubmit={confirm} noValidate>
                <div className="grid gap-5 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-start">
                    <figure className="bg-base-100 border-base-300 rounded-box size-48 self-start border p-2">
                        <div
                            role="img"
                            aria-label="QR code for your authenticator app"
                            className="size-full [&>svg]:size-full"
                            // Built by the server (Fortify); no user input reaches this markup.
                            dangerouslySetInnerHTML={{ __html: qrSvg }}
                        />
                    </figure>

                    <fieldset className="fieldset min-w-0 gap-2 py-0" disabled={processing}>
                        <p className="label text-base-content text-sm font-medium capitalize">Can&apos;t Scan? Enter This Key</p>
                        <p className="bg-base-200 border-base-300 rounded-field border px-3 py-2 font-mono text-sm tracking-wider break-all">
                            {groupSecret(secret)}
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

                <div className="border-base-300 mt-6 flex items-center justify-end gap-2 border-t pt-4">
                    <button
                        type="submit"
                        className="btn btn-primary btn-sm capitalize"
                        disabled={processing || data.code.length !== 6}
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
            </form>
        </EnrolmentLayout>
    );
}
