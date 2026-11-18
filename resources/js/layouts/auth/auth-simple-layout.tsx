import { APP_BRAND, AppLogoMark } from '@/components/app-logo';
import { Link } from '@inertiajs/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

/**
 * UI-4: the other account pages (forgot and reset password, confirm, verify)
 * share the sign-in page's look: a centred white card on L0 with the brand
 * stripe on top (design.md sections 2 and 5). Token classes only.
 */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="bg-background text-foreground flex min-h-svh flex-col items-center justify-center px-3 py-10">
            <main className="bg-card w-full max-w-md overflow-hidden rounded-2xl border shadow-xs">
                <div className="flex flex-col gap-6 px-6 pt-8 pb-8 sm:px-8">
                    <Link
                        href={route('home')}
                        className="focus-visible:ring-ring flex items-center gap-3 self-start rounded-md outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                    >
                        <AppLogoMark />
                        <span className="grid leading-tight">
                            <span className="text-sm font-semibold">{APP_BRAND.name}</span>
                            <span className="text-muted-foreground text-xs">{APP_BRAND.descriptor}</span>
                        </span>
                    </Link>

                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-[-0.01em]">{title}</h1>
                        {description && <p className="text-muted-foreground text-[13px]">{description}</p>}
                    </div>

                    {children}
                </div>
            </main>
        </div>
    );
}
