import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
};

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

const inputBase =
    'block h-10 w-full rounded-lg border bg-card px-4 text-sm text-foreground shadow-sm outline-none transition placeholder:text-muted-foreground focus:ring-2';
const inputOk = 'border-input focus:border-ring focus:ring-ring/20';
const inputBad = 'border-destructive focus:border-destructive focus:ring-destructive/15';

function FieldError({ id, message }: { id: string; message?: string }) {
    if (!message) return null;
    return (
        <p id={id} className="text-destructive mt-1.5 text-[13px]">
            {message}
        </p>
    );
}

function LogoMark() {
    return (
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" className="text-primary" aria-hidden="true">
            <rect x="2" y="2" width="14" height="14" rx="4" fill="currentColor" fillOpacity="0.45" />
            <rect x="8" y="8" width="14" height="14" rx="4" fill="currentColor" />
        </svg>
    );
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="bg-background text-foreground min-h-screen font-sans antialiased">
            <Head title="Sign in — NIPDEC" />

            <main className="mx-auto w-full max-w-96 px-5 pt-10 pb-10 min-[420px]:px-0">
                <div className="flex items-center justify-center gap-2">
                    <LogoMark />
                    <span className="text-foreground text-lg font-semibold">NIPDEC</span>
                </div>

                <h1 className="text-foreground mt-12 text-center text-xl font-semibold">Sign in to your account</h1>
                <p className="text-muted-foreground mt-2 text-center text-sm">Welcome back! Please enter your details.</p>

                {status && (
                    <p role="status" className="text-status-success-foreground mt-6 text-center text-sm font-medium">
                        {status}
                    </p>
                )}

                <form className="mt-8" onSubmit={submit}>
                    <div>
                        <label htmlFor="email" className="text-foreground mb-2 block text-sm font-medium">
                            Email
                        </label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            required
                            autoFocus
                            autoComplete="email"
                            placeholder="you@health.gov.tt"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            aria-invalid={errors.email ? true : undefined}
                            aria-describedby={errors.email ? 'email-error' : undefined}
                            className={`${inputBase} ${errors.email ? inputBad : inputOk}`}
                        />
                        <FieldError id="email-error" message={errors.email} />
                    </div>

                    <div className="mt-4">
                        <div className="mb-2 flex items-center justify-between">
                            <label htmlFor="password" className="text-foreground block text-sm font-medium">
                                Password
                            </label>
                            {canResetPassword && (
                                <Link
                                    href={route('password.request')}
                                    className="text-muted-foreground hover:text-foreground focus-visible:text-foreground focus-visible:ring-ring rounded-sm text-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-offset-2"
                                >
                                    Forgot?
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
                            className={`${inputBase} ${errors.password ? inputBad : inputOk}`}
                        />
                        <FieldError id="password-error" message={errors.password} />
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        aria-busy={processing}
                        className="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring disabled:hover:bg-primary mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-lg text-sm font-semibold transition outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {processing && (
                            <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.3" strokeWidth="3" />
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
                            </svg>
                        )}
                        Sign in
                    </button>
                </form>

                <p className="text-muted-foreground mt-6 text-center text-[13px]">
                    Access is by invitation only. Contact the CMO&apos;s office for an account.
                </p>
            </main>
        </div>
    );
}
