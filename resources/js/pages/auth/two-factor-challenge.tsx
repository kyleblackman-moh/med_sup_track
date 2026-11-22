import InputError from '@/components/input-error';
import { CodeInput } from '@/components/two-factor/code-input';
import AuthLayout from '@/layouts/auth-layout';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { KeyRound, ShieldCheck } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

type ChallengeForm = {
    code: string;
    recovery_code: string;
};

/**
 * The sign-in's second step (Blueprint pages/two-factor-challenge, in the
 * sign-in card). The 6-digit authenticator code by default; "Use a recovery
 * code" swaps in the recovery-code field, and back. Only one of the two is
 * sent. Fortify checks it and answers wrong codes inline; the buttons end
 * the form (Back to Sign In first, Verify last).
 */
export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const codeRef = useRef<HTMLInputElement>(null);
    const recoveryRef = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, errors, reset, clearErrors, transform } = useForm<ChallengeForm>({
        code: '',
        recovery_code: '',
    });

    const toggle = () => {
        const next = !recovery;
        setRecovery(next);
        reset();
        clearErrors();
        // Focus the field that has just appeared.
        window.setTimeout(() => (next ? recoveryRef.current : codeRef.current)?.focus(), 0);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((form) => (recovery ? { recovery_code: form.recovery_code.trim() } : { code: form.code }));
        post(route('two-factor.login.store'), {
            onFinish: () => reset(),
        });
    };

    return (
        <AuthLayout
            title={recovery ? 'Use a Recovery Code' : 'Enter Your Authentication Code'}
            description={
                recovery
                    ? 'Enter one of the recovery codes you saved when you set up two-factor authentication. Each code works once.'
                    : 'Open the authenticator app on your phone and enter the 6-digit code it shows for this account.'
            }
        >
            <Head title="Two-Factor Authentication" />

            <form onSubmit={submit} noValidate>
                <fieldset className="fieldset gap-2 py-0" disabled={processing}>
                    {recovery ? (
                        <>
                            <label htmlFor="recovery_code" className="label text-base-content text-sm font-medium capitalize">
                                Recovery Code
                            </label>
                            <input
                                ref={recoveryRef}
                                id="recovery_code"
                                name="recovery_code"
                                type="text"
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                required
                                value={data.recovery_code}
                                onChange={(e) => setData('recovery_code', e.target.value)}
                                aria-invalid={errors.recovery_code ? true : undefined}
                                aria-describedby={errors.recovery_code ? 'recovery_code-error' : 'recovery_code-hint'}
                                className={cn('input w-full font-mono', errors.recovery_code && 'input-error')}
                            />
                            {errors.recovery_code ? (
                                <InputError id="recovery_code-error" message={errors.recovery_code} />
                            ) : (
                                <p id="recovery_code-hint" className="text-helper text-base-content/70">
                                    For example abcde12345-fghij67890, as written in your recovery codes file.
                                </p>
                            )}
                        </>
                    ) : (
                        <>
                            <label htmlFor="code" className="label text-base-content text-sm font-medium capitalize">
                                6-Digit Code
                            </label>
                            <CodeInput
                                ref={codeRef}
                                id="code"
                                value={data.code}
                                onChange={(value) => setData('code', value)}
                                invalid={!!errors.code}
                                describedBy={errors.code ? 'code-error' : 'code-hint'}
                                autoFocus
                            />
                            {errors.code ? (
                                <InputError id="code-error" message={errors.code} />
                            ) : (
                                <p id="code-hint" className="text-helper text-base-content/70">
                                    The code changes every 30 seconds. If it is rejected, wait for the next one.
                                </p>
                            )}
                        </>
                    )}

                    <button type="button" className="link link-hover mt-2 self-start text-sm" onClick={toggle}>
                        {recovery ? 'Use an authentication code' : 'Use a recovery code'}
                    </button>
                </fieldset>

                <div className="border-base-300 mt-6 flex items-center justify-end gap-2 border-t pt-4">
                    <Link href={route('login')} className="btn btn-sm btn-ghost capitalize">
                        Back to Sign In
                    </Link>
                    <button type="submit" disabled={processing} aria-busy={processing || undefined} className="btn btn-primary btn-sm capitalize">
                        {processing ? (
                            <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                        ) : recovery ? (
                            <KeyRound className="size-4" aria-hidden="true" />
                        ) : (
                            <ShieldCheck className="size-4" aria-hidden="true" />
                        )}
                        Verify
                    </button>
                </div>
            </form>
        </AuthLayout>
    );
}
