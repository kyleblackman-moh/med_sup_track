import InputError from '@/components/input-error';
import { AuthShell } from '@/layouts/auth/auth-shell';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { CircleCheck, Eye, EyeOff, KeyRound, LogIn, Mail, Smartphone } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type LoginForm = {
    email: string;
    password: string;
};

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

/**
 * The sign-in page (Blueprint pages/sign-in, Clean UI) in the account shell:
 * the Ministry's identity panel on large screens, the form on the right.
 * Email and password (with show/hide), the recovery link beside the
 * password, one primary Sign In, then what happens next: the two-step code,
 * and that accounts come by invitation from the CMO's office.
 */
export default function Login({ status, canResetPassword }: LoginProps) {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthShell>
            <Head title="Sign in" />

            <h1 className="text-2xl font-semibold capitalize">Sign In</h1>
            <p className="text-base-content/70 mt-1 text-sm">Use the email address your account was issued to.</p>

            {status && (
                <div role="status" className="alert alert-success alert-soft mt-6 text-sm">
                    <CircleCheck className="size-4 shrink-0" aria-hidden="true" />
                    <span>{status}</span>
                </div>
            )}

            <form className="mt-8" onSubmit={submit} noValidate>
                <fieldset className="fieldset gap-5 py-0">
                    <div className="grid gap-1.5">
                        <label htmlFor="email" className="label text-base-content text-sm font-medium capitalize">
                            Email
                        </label>
                        <label className={cn('input w-full', errors.email && 'input-error')}>
                            <Mail className="text-base-content/60 size-4 shrink-0" aria-hidden="true" />
                            <input
                                id="email"
                                name="email"
                                type="email"
                                required
                                autoFocus
                                autoComplete="username"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                aria-invalid={errors.email ? true : undefined}
                                aria-describedby={errors.email ? 'email-error' : undefined}
                                className="grow"
                            />
                        </label>
                        <InputError id="email-error" message={errors.email} />
                    </div>

                    <div className="grid gap-1.5">
                        <div className="flex items-center justify-between gap-3">
                            <label htmlFor="password" className="label text-base-content text-sm font-medium capitalize">
                                Password
                            </label>
                            {canResetPassword && (
                                <Link href={route('password.request')} className="link link-primary text-sm">
                                    Forgot password?
                                </Link>
                            )}
                        </div>
                        <label className={cn('input w-full pr-1', errors.password && 'input-error')}>
                            <KeyRound className="text-base-content/60 size-4 shrink-0" aria-hidden="true" />
                            <input
                                id="password"
                                name="password"
                                type={showPassword ? 'text' : 'password'}
                                required
                                autoComplete="current-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                aria-invalid={errors.password ? true : undefined}
                                aria-describedby={errors.password ? 'password-error' : undefined}
                                className="grow"
                            />
                            <button
                                type="button"
                                className="btn btn-ghost btn-sm btn-square"
                                aria-label={showPassword ? 'Hide password' : 'Show password'}
                                aria-pressed={showPassword}
                                aria-controls="password"
                                onClick={() => setShowPassword((shown) => !shown)}
                            >
                                {showPassword ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
                            </button>
                        </label>
                        <InputError id="password-error" message={errors.password} />
                    </div>
                </fieldset>

                <button type="submit" disabled={processing} aria-busy={processing} className="btn btn-primary btn-block mt-8 capitalize">
                    {processing ? (
                        <span className="loading loading-spinner loading-sm" aria-hidden="true" />
                    ) : (
                        <LogIn className="size-4" aria-hidden="true" />
                    )}
                    {processing ? 'Signing In…' : 'Sign In'}
                </button>
            </form>

            <div className="divider my-8" />

            <ul className="text-base-content/70 grid gap-3 text-sm">
                <li className="flex items-start gap-2">
                    <Smartphone className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>After your password, you&apos;ll confirm with a code from your authenticator app.</span>
                </li>
                <li className="flex items-start gap-2">
                    <Mail className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>Accounts are by invitation only. To get one, contact the CMO&apos;s office.</span>
                </li>
            </ul>
        </AuthShell>
    );
}
