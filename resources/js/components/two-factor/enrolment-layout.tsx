import { APP_BRAND, AppLogoMark } from '@/components/app-logo';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';
import { LogOut, ShieldCheck } from 'lucide-react';
import { type ReactNode } from 'react';

const STEPS = ['Set Password', 'Pair Authenticator', 'Save Recovery Codes'] as const;

interface EnrolmentLayoutProps {
    title: string;
    description: string;
    /** 0, 1 or 2: where this page sits in account activation. Omit off the activation path (e.g. after a regenerate). */
    step?: 0 | 1 | 2;
    children: ReactNode;
}

/**
 * Account activation and MFA enrolment (Blueprint pages/invite-acceptance and
 * pages/security-settings, Clean UI): the sign-in page's flat card on base-200,
 * widened for the QR code, with the three-step ceremony strip (Set Password,
 * Pair Authenticator, Save Recovery Codes) so a new user always sees where
 * they are. Outside the app shell on purpose: until enrolment is done the
 * server lets the user reach only these pages and Sign Out. The footer names
 * the signed-in account and offers Sign Out; a guest sees the invitation rule.
 */
export default function EnrolmentLayout({ title, description, step, children }: EnrolmentLayoutProps) {
    const user = usePage<{ auth?: { user?: { email: string } | null } }>().props.auth?.user ?? null;

    return (
        <div className="bg-base-200 text-base-content flex min-h-svh flex-col items-center justify-center px-4 py-10 font-sans antialiased">
            <main className="card bg-base-100 border-base-300 w-full max-w-lg overflow-hidden border">
                <div className="card-body gap-6 p-6 sm:p-8">
                    <div className="flex items-center gap-3">
                        <AppLogoMark />
                        <span className="grid leading-tight">
                            <span className="text-sm font-semibold">{APP_BRAND.name}</span>
                            <span className="text-base-content/70 text-xs">
                                {APP_BRAND.descriptor}, {APP_BRAND.country}
                            </span>
                        </span>
                    </div>

                    {step !== undefined && (
                        <ol aria-label="Account setup" className="steps w-full text-xs">
                            {STEPS.map((label, i) => (
                                <li
                                    key={label}
                                    className={cn('step capitalize', i <= step && 'step-primary')}
                                    aria-current={i === step ? 'step' : undefined}
                                >
                                    {label}
                                    {i < step && <span className="sr-only"> (done)</span>}
                                </li>
                            ))}
                        </ol>
                    )}

                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold capitalize">{title}</h1>
                        <p className="text-base-content/70 text-sm">{description}</p>
                    </div>

                    {children}
                </div>

                <div className="bg-base-200 border-base-300 text-base-content/70 flex flex-wrap items-center justify-between gap-2 border-t px-6 py-4 text-sm sm:px-8">
                    {user ? (
                        <>
                            <span className="min-w-0 break-all">
                                Signed in as <span className="text-base-content font-medium">{user.email}</span>
                            </span>
                            <Link href={route('logout')} method="post" as="button" className="link link-hover inline-flex items-center gap-1">
                                <LogOut className="size-4" aria-hidden="true" />
                                Sign Out
                            </Link>
                        </>
                    ) : (
                        <p className="flex items-start gap-2">
                            <ShieldCheck className="text-base-content/60 mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            Access is by invitation only. Contact the CMO&apos;s office for an account.
                        </p>
                    )}
                </div>
            </main>
        </div>
    );
}
