import { APP_BRAND } from '@/components/app-logo';
import AppLogoIcon from '@/components/app-logo-icon';
import { BrandStripe } from '@/components/brand-stripe';
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

// design.md section 5: 1px --input border, 8px radius; focus is a --primary border plus a
// 3px --ring-wash halo; invalid is a --destructive border plus a 3px --destructive-wash halo.
const inputBase =
    'block h-11 w-full rounded-lg border bg-card px-3 text-sm text-foreground outline-none transition-[border-color,box-shadow] placeholder:text-muted-foreground focus:ring-[3px] md:h-9';
const inputOk = 'border-input focus:border-primary focus:ring-ring-wash';
const inputBad = 'border-destructive focus:border-destructive focus:ring-destructive-wash';

function FieldError({ id, message }: { id: string; message?: string }) {
    if (!message) return null;
    return (
        <p id={id} className="text-destructive-text mt-1.5 text-[13px]">
            {message}
        </p>
    );
}

/**
 * UI-4: the sign-in page in the Layered Surface Theme (design.md sections 2, 5
 * and 11): a centred white card on the L0 background with the brand stripe on
 * top, token classes only. The product name and logo are the shell's own
 * (APP_BRAND, AppLogoIcon); no logo or tagline is invented here.
 */
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
        <div className="bg-background text-foreground flex min-h-svh flex-col items-center justify-center px-3 py-10 font-sans antialiased">
            <Head title={`Sign in - ${APP_BRAND.name}`} />

            <main className="bg-card w-full max-w-md overflow-hidden rounded-2xl border shadow-xs">
                <BrandStripe />

                <div className="px-6 pt-8 pb-8 sm:px-8">
                    <div className="flex items-center gap-3">
                        <span className="bg-primary text-primary-foreground flex size-[34px] items-center justify-center rounded-[4px] rounded-bl-2xl">
                            <AppLogoIcon className="size-5 fill-current" aria-hidden="true" />
                        </span>
                        <span className="grid leading-tight">
                            <span className="text-sm font-semibold">{APP_BRAND.name}</span>
                            <span className="text-muted-foreground text-xs">{APP_BRAND.descriptor}</span>
                        </span>
                    </div>

                    <h1 className="mt-8 text-xl font-semibold tracking-[-0.01em]">Sign in to your account</h1>
                    <p className="text-muted-foreground mt-1 text-[13px]">Enter the email address and password for your account.</p>

                    {status && (
                        <p role="status" className="text-status-success-foreground mt-6 text-sm font-medium">
                            {status}
                        </p>
                    )}

                    <form className="mt-6" onSubmit={submit}>
                        <div>
                            <label htmlFor="email" className="text-foreground mb-1.5 block text-sm font-medium">
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
                                className={`${inputBase} ${errors.email ? inputBad : inputOk}`}
                            />
                            <FieldError id="email-error" message={errors.email} />
                        </div>

                        <div className="mt-4">
                            <div className="mb-1.5 flex items-center justify-between">
                                <label htmlFor="password" className="text-foreground block text-sm font-medium">
                                    Password
                                </label>
                                {canResetPassword && (
                                    <Link
                                        href={route('password.request')}
                                        className="text-primary hover:text-primary-strong focus-visible:ring-ring rounded-sm text-[13px] outline-none hover:underline focus-visible:ring-2 focus-visible:ring-offset-2"
                                    >
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
                                className={`${inputBase} ${errors.password ? inputBad : inputOk}`}
                            />
                            <FieldError id="password-error" message={errors.password} />
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            aria-busy={processing}
                            className="bg-primary text-primary-foreground border-primary-strong shadow-primary hover:bg-primary-strong focus-visible:ring-ring disabled:hover:bg-primary mt-6 inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg border text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 md:h-9"
                        >
                            {processing && (
                                <svg className="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.3" strokeWidth="3" />
                                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
                                </svg>
                            )}
                            Sign in
                        </button>
                    </form>

                    <p className="text-muted-foreground mt-6 text-[13px]">
                        Access is by invitation only. Contact the CMO&apos;s office for an account.
                    </p>
                </div>
            </main>
        </div>
    );
}
