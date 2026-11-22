import { APP_BRAND, AppLogoMark } from '@/components/app-logo';
import { Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

/**
 * The other account pages (forgot and reset password, confirm, verify) share
 * the sign-in page's look (Blueprint pages/sign-in, Clean UI): one flat
 * bordered card on base-200, the Ministry identity on top, the page's own
 * content in the body, and the invitation-only rule as the footer band.
 * daisyUI classes and "moh" theme tokens only.
 */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="bg-base-200 text-base-content flex min-h-svh flex-col items-center justify-center px-4 py-10 font-sans antialiased">
            <main className="card bg-base-100 border-base-300 w-full max-w-md overflow-hidden border">
                <div className="card-body gap-6 p-6 sm:p-8">
                    <Link
                        href={route('home')}
                        className="rounded-field focus-visible:outline-primary flex items-center gap-3 self-start focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        <AppLogoMark />
                        <span className="grid leading-tight">
                            <span className="text-sm font-semibold">{APP_BRAND.name}</span>
                            <span className="text-base-content/70 text-xs">
                                {APP_BRAND.descriptor}, {APP_BRAND.country}
                            </span>
                        </span>
                    </Link>

                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold capitalize">{title}</h1>
                        {description && <p className="text-base-content/70 text-sm">{description}</p>}
                    </div>

                    {children}
                </div>

                <div className="bg-base-200 border-base-300 text-base-content/70 flex items-start gap-2 border-t px-6 py-4 text-sm sm:px-8">
                    <ShieldCheck className="text-base-content/60 mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>Access is by invitation only. Contact the CMO&apos;s office for an account.</p>
                </div>
            </main>
        </div>
    );
}
