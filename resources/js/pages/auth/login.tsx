import { APP_BRAND, AppLogoMark } from '@/components/app-logo';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { CircleCheck, LogIn, ShieldCheck } from 'lucide-react';
import { FormEventHandler } from 'react';

type LoginForm = {
    email: string;
    password: string;
};

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

/**
 * The sign-in page (Blueprint pages/sign-in, Clean UI): one flat bordered card
 * on base-200 with the Ministry identity on top, the email and password form,
 * and the invitation-only rule as the card's footer band, because accounts are
 * issued by the CMO's office. The product name and logo are the shell's own
 * (APP_BRAND, AppLogoMark); daisyUI classes and "moh" theme tokens only.
 */
export default function Login({ status, canResetPassword }: LoginProps) {
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
        <div className="bg-base-200 text-base-content flex min-h-svh flex-col items-center justify-center px-4 py-10 font-sans antialiased">
            <Head title="Sign in" />

            <main className="card bg-base-100 border-base-300 w-full max-w-md overflow-hidden border">
                <div className="card-body gap-0 p-6 sm:p-8">
                    <div className="flex items-center gap-3">
                        <AppLogoMark />
                        <span className="grid leading-tight">
                            <span className="text-sm font-semibold">{APP_BRAND.name}</span>
                            <span className="text-base-content/70 text-xs">
                                {APP_BRAND.descriptor}, {APP_BRAND.country}
                            </span>
                        </span>
                    </div>

                    <h1 className="mt-8 text-xl font-semibold capitalize">Sign in to Your Account</h1>
                    <p className="text-base-content/70 mt-1 text-sm">Enter the email address and password for your account.</p>

                    {status && (
                        <div role="status" className="alert alert-success alert-soft mt-6 text-sm">
                            <CircleCheck className="size-4 shrink-0" aria-hidden="true" />
                            <span>{status}</span>
                        </div>
                    )}

                    <form className="mt-6" onSubmit={submit}>
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
                                    autoComplete="email"
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
                                        <Link href={route('password.request')} className="link text-sm">
                                            Forgot password?
                                        </Link>
                                    )}
                                </div>
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    required
                                    autoComplete="current-password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    aria-invalid={errors.password ? true : undefined}
                                    aria-describedby={errors.password ? 'password-error' : undefined}
                                    className={cn('input w-full', errors.password && 'input-error')}
                                />
                                <InputError id="password-error" message={errors.password} />
                            </div>
                        </fieldset>

                        <button type="submit" disabled={processing} aria-busy={processing} className="btn btn-primary btn-block mt-6 capitalize">
                            {processing ? (
                                <span className="loading loading-spinner loading-sm" aria-hidden="true" />
                            ) : (
                                <LogIn className="size-4" aria-hidden="true" />
                            )}
                            Sign in
                        </button>
                    </form>
                </div>

                <div className="bg-base-200 border-base-300 text-base-content/70 flex items-start gap-2 border-t px-6 py-4 text-sm sm:px-8">
                    <ShieldCheck className="text-base-content/60 mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>Access is by invitation only. Contact the CMO&apos;s office for an account.</p>
                </div>
            </main>
        </div>
    );
}
