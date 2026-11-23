import InputError from '@/components/input-error';
import EnrolmentLayout from '@/components/two-factor/enrolment-layout';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { CircleAlert, KeyRound, LogIn } from 'lucide-react';
import { FormEventHandler } from 'react';

interface ActivateProps {
    /** The server checked the signed link and that it is still open. */
    valid: boolean;
    invitation: string | null;
    token: string | null;
    email: string | null;
    /** Set when the link is expired, used or revoked. */
    message: string | null;
}

type ActivateForm = {
    token: string;
    password: string;
    password_confirmation: string;
};

/**
 * IT-ADMIN (spec 8.2): the emailed activation link (Blueprint pages/invite-acceptance).
 * Step 1 of 3: the person sees which account the invitation is for and sets a
 * password once. The server then sends them to sign in, and the sign-in forces
 * authenticator enrolment before anything else. An expired, used or revoked
 * link shows the server's reason and the way back; no form.
 */
export default function Activate({ valid, invitation, token, email, message }: ActivateProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ActivateForm>({
        token: token ?? '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!invitation) return;
        post(route('activation.store', invitation), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    if (!valid || !invitation) {
        return (
            <EnrolmentLayout title="Activation Link Not Valid" description="This link can no longer be used to set up an account.">
                <Head title="Activate Account" />
                <div role="alert" className="alert alert-error alert-soft items-start text-sm">
                    <CircleAlert className="size-4 shrink-0" aria-hidden="true" />
                    <span>{message}</span>
                </div>
                <div className="border-base-300 flex justify-end border-t pt-4">
                    <Link href={route('login')} className="btn btn-sm capitalize">
                        <LogIn className="size-4" aria-hidden="true" />
                        Go to Sign In
                    </Link>
                </div>
            </EnrolmentLayout>
        );
    }

    return (
        <EnrolmentLayout
            step={0}
            title="Activate Your Account"
            description="Choose a password for your account. After you sign in, you will pair an authenticator app on your phone."
        >
            <Head title="Activate Account" />

            {errors.token && (
                <div role="alert" className="alert alert-error alert-soft items-start text-sm">
                    <CircleAlert className="size-4 shrink-0" aria-hidden="true" />
                    <span>{errors.token}</span>
                </div>
            )}

            <form onSubmit={submit} noValidate>
                <fieldset className="fieldset gap-4 py-0" disabled={processing}>
                    <div className="grid gap-1.5">
                        <span className="label text-base-content text-sm font-medium capitalize">Account</span>
                        <p className="bg-base-200 border-base-300 rounded-field border px-3 py-2 text-sm break-all">{email}</p>
                    </div>

                    <div className="grid gap-1.5">
                        <label htmlFor="password" className="label text-base-content text-sm font-medium capitalize">
                            New Password
                        </label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autoFocus
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            aria-invalid={errors.password ? true : undefined}
                            aria-describedby={errors.password ? 'password-error password-hint' : 'password-hint'}
                            className={cn('input input-sm w-full', errors.password && 'input-error')}
                        />
                        <p id="password-hint" className="text-helper text-base-content/70">
                            At least 12 characters, with upper and lower case letters and a number. Passwords found in known data breaches are
                            refused.
                        </p>
                        <InputError id="password-error" message={errors.password} />
                    </div>

                    <div className="grid gap-1.5">
                        <label htmlFor="password_confirmation" className="label text-base-content text-sm font-medium capitalize">
                            Confirm Password
                        </label>
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            required
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            aria-invalid={errors.password_confirmation ? true : undefined}
                            aria-describedby={errors.password_confirmation ? 'password_confirmation-error' : undefined}
                            className={cn('input input-sm w-full', errors.password_confirmation && 'input-error')}
                        />
                        <InputError id="password_confirmation-error" message={errors.password_confirmation} />
                    </div>
                </fieldset>

                <div className="border-base-300 mt-6 flex items-center justify-end gap-2 border-t pt-4">
                    <Link href={route('login')} className="btn btn-sm btn-ghost capitalize">
                        Back to Sign In
                    </Link>
                    <button type="submit" disabled={processing} aria-busy={processing || undefined} className="btn btn-primary btn-sm capitalize">
                        {processing ? (
                            <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                        ) : (
                            <KeyRound className="size-4" aria-hidden="true" />
                        )}
                        Set Password
                    </button>
                </div>
            </form>
        </EnrolmentLayout>
    );
}
