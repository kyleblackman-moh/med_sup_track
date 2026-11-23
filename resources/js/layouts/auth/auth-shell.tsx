import { APP_BRAND, AppLogoMark } from '@/components/app-logo';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * Two broad ribbon strokes behind the card, drawn in the theme's primary and
 * base-content tones at low opacity (currentColor, no fixed colours).
 * Decorative only: hidden from assistive tech, never catches the pointer, and
 * clipped to the page so it never causes sideways scrolling.
 */
function Ribbons() {
    return (
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10 overflow-hidden">
            <svg className="text-primary/15 absolute -bottom-24 -left-40 h-[34rem] w-[46rem] max-w-none" viewBox="0 0 736 544" fill="none">
                <path
                    d="M20 520C60 420 120 300 220 260C320 220 380 330 320 400C260 470 150 420 170 330C190 240 330 170 470 210C610 250 700 360 720 470"
                    stroke="currentColor"
                    strokeWidth="72"
                    strokeLinecap="round"
                />
            </svg>
            <svg className="text-base-content/5 absolute -top-16 -right-48 h-[38rem] w-[40rem] max-w-none" viewBox="0 0 640 608" fill="none">
                <path
                    d="M600 20C520 60 470 160 500 260C530 360 620 420 560 500C500 580 360 560 300 470C240 380 300 260 220 190C140 120 60 160 20 230"
                    stroke="currentColor"
                    strokeWidth="72"
                    strokeLinecap="round"
                />
            </svg>
        </div>
    );
}

/**
 * The account pages' shell (Blueprint pages/sign-in, Clean UI): a quiet
 * base-200 canvas with two soft ribbon strokes, the product's brand centred
 * above one compact card, and an optional note under the card. Every account
 * page (sign in, forgot and reset password, confirm, verify, activation, the
 * MFA challenge) shares it.
 */
export function AuthShell({ children, footer }: { children: ReactNode; footer?: ReactNode }) {
    return (
        <div className="bg-base-200 text-base-content relative isolate flex min-h-svh flex-col items-center px-4 py-10 font-sans antialiased">
            <Ribbons />

            <Link
                href={route('home')}
                className="rounded-field focus-visible:outline-primary flex items-center gap-3 focus-visible:outline-2 focus-visible:outline-offset-2"
            >
                <AppLogoMark />
                <span className="grid leading-tight">
                    <span className="font-semibold">{APP_BRAND.name}</span>
                    <span className="text-base-content/70 text-xs">
                        {APP_BRAND.descriptor}, {APP_BRAND.country}
                    </span>
                </span>
            </Link>

            <div className="flex w-full flex-1 flex-col items-center justify-center py-10">
                <main className="card bg-base-100 border-base-300 w-full max-w-sm border shadow-sm">
                    <div className="card-body gap-0 p-6 sm:p-8">{children}</div>
                </main>
                {footer && <div className="text-base-content/70 mt-6 w-full max-w-sm text-center text-sm">{footer}</div>}
            </div>
        </div>
    );
}
