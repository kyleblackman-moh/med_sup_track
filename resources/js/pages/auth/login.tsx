import InputError from '@/components/input-error';
import { AuthShell } from '@/layouts/auth/auth-shell';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { CircleCheck, Eye, EyeOff } from 'lucide-react';
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
 * one compact centred card with the title, email, password (show/hide) with
 * the recovery link beside its label, and one Sign In. Under the card: the
 * two-step code that follows and that accounts come by invitation.
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
        <AuthShell
            footer={
                <>
                    <p>After your password, you&apos;ll confirm with a code from your authenticator app.</p>
                    <p className="mt-1">Accounts are by invitation only. Contact the CMO&apos;s office for one.</p>
                </>
            }
        >
            <Head title="Sign in" />

            <div className="text-center">
                <h1 className="text-xl font-semibold capitalize">Sign In to Your Account</h1>
                <p className="text-base-content/70 mt-1 text-sm">Enter the email and password for your account.</p>
            </div>

            {status && (
                <div role="status" className="alert alert-success alert-soft mt-6 text-sm">
                    <CircleCheck className="size-4 shrink-0" aria-hidden="true" />
                    <span>{status}</span>
                </div>
            )}

            <form className="mt-6" onSubmit={submit} noValidate>
                <fieldset className="fieldset gap-4 py-0">
                    <div className="grid gap-1.5">
                        <label htmlFor="email" className="label text-base-content text-sm font-medium capitalize">
                            Email
                        </label>
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
                            className={cn('input w-full', errors.email && 'input-error')}
                        />
                        <InputError id="email-error" message={errors.email} />
                    </div>

                    <div className="grid gap-1.5">
                        <div className="flex items-center justify-between gap-3">
                            <label htmlFor="password" className="label text-base-content text-sm font-medium capitalize">
                                Password
                            </label>
                            {canResetPassword && (
                                <Link href={route('password.request')} className="link link-primary link-hover text-sm font-medium">
                                    Forgot password?
                                </Link>
                            )}
                        </div>
                        <label className={cn('input w-full pr-1', errors.password && 'input-error')}>
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

                <button
                    type="submit"
                    disabled={processing}
                    aria-busy={processing}
                    className="btn btn-primary btn-block from-primary to-info mt-6 border-0 bg-linear-to-r capitalize"
                >
                    {processing && <span className="loading loading-spinner loading-sm" aria-hidden="true" />}
                    {processing ? 'Signing In…' : 'Sign In'}
                </button>
            </form>
        </AuthShell>
    );
}
