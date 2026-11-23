import { APP_BRAND, AppLogoMark } from '@/components/app-logo';
import { Link } from '@inertiajs/react';
import { FileCheck2, FileInput, Landmark, Scale, ShieldCheck, Stethoscope, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

/** The route every non-objection request takes through the Ministry, shown beside the form. */
const JOURNEY: { label: string; detail: string; icon: LucideIcon }[] = [
    { label: 'Submitted', detail: 'The vendor sends the request and its documents.', icon: FileInput },
    { label: 'CMO Review', detail: 'The CMO, with the Technocrats (technical) and CAB (funding).', icon: Stethoscope },
    { label: 'Legal', detail: 'The Legal Unit reviews the file.', icon: Scale },
    { label: 'Permanent Secretary', detail: 'The PS grants or returns the request.', icon: Landmark },
    { label: 'Letter Issued', detail: 'The non-objection letter goes to the vendor.', icon: FileCheck2 },
];

function Brand({ inverse = false }: { inverse?: boolean }) {
    return (
        <span className="flex items-center gap-3">
            <AppLogoMark className={inverse ? 'bg-primary-content text-primary' : undefined} />
            <span className="grid leading-tight">
                <span className="text-sm font-semibold">{APP_BRAND.name}</span>
                <span className={inverse ? 'text-primary-content/80 text-xs' : 'text-base-content/70 text-xs'}>
                    {APP_BRAND.descriptor}, {APP_BRAND.country}
                </span>
            </span>
        </span>
    );
}

/**
 * The account pages' shell (Blueprint pages/sign-in, Clean UI): on large
 * screens an asymmetric split, the Ministry's identity panel on the left in
 * the primary colour with the five stages a request passes through, and the
 * form on a plain base surface on the right. Below lg the panel is dropped
 * and the brand sits above the form. Every account page (sign in, forgot and
 * reset password, confirm, verify, activation, the MFA challenge) shares it.
 */
export function AuthShell({ children }: { children: ReactNode }) {
    return (
        <div className="bg-base-100 text-base-content grid min-h-svh font-sans antialiased lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <aside className="bg-primary text-primary-content hidden flex-col justify-between gap-10 p-10 lg:flex xl:p-14">
                <Brand inverse />

                <div className="max-w-md space-y-8">
                    <p className="text-3xl leading-tight font-semibold text-balance">
                        Every non-objection request, tracked from submission to letter.
                    </p>
                    <ul className="steps steps-vertical" aria-label="How a request moves through the Ministry">
                        {JOURNEY.map((stage) => (
                            <li key={stage.label} className="step">
                                <span className="step-icon">
                                    <stage.icon className="size-4" aria-hidden="true" />
                                </span>
                                <span className="grid text-left">
                                    <span className="font-semibold capitalize">{stage.label}</span>
                                    <span className="text-primary-content/80 text-sm">{stage.detail}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="text-primary-content/80 flex items-start gap-2 text-sm">
                    <ShieldCheck className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    Office of the Chief Medical Officer. Accounts are issued by invitation and protected by two-step sign-in.
                </p>
            </aside>

            <main className="flex flex-col px-4 py-8 sm:px-10 lg:px-16">
                <Link
                    href={route('home')}
                    className="rounded-field focus-visible:outline-primary self-start focus-visible:outline-2 focus-visible:outline-offset-2 lg:hidden"
                >
                    <Brand />
                </Link>
                <div className="flex flex-1 items-center py-10">
                    <div className="w-full max-w-sm">{children}</div>
                </div>
            </main>
        </div>
    );
}
