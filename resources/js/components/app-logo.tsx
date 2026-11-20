import { cn } from '@/lib/utils';
import { ShieldPlus } from 'lucide-react';

/** The system name and descriptor shown next to the logo. Change them here only. */
export const APP_BRAND = {
    name: 'Medication Procurement',
    descriptor: 'Ministry of Health',
    /** V2-4: the second line of the sidebar header block. */
    country: 'Trinidad & Tobago',
} as const;

/**
 * The logo mark, used by the sidebar and the sign-in pages: a 34px --primary
 * tile, corners 4px except one 16px bottom-left (design.md section 6), with
 * the shield-plus mark (V2-2c). Decorative: the system name beside it is the text.
 */
export function AppLogoMark({ className }: { className?: string }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'bg-primary text-primary-foreground flex aspect-square size-[34px] shrink-0 items-center justify-center rounded-[4px] rounded-bl-2xl',
                className,
            )}
        >
            <ShieldPlus className="size-5" />
        </span>
    );
}

export default function AppLogo() {
    return (
        <>
            <AppLogoMark className="bg-sidebar-primary text-sidebar-primary-foreground" />
            {/* V2-2c: the system name may wrap to two lines rather than being cut off. */}
            <div className="ml-1 grid flex-1 gap-0.5 text-left text-sm">
                <span className="leading-tight font-semibold">{APP_BRAND.name}</span>
                <span className="text-muted-foreground text-xs leading-tight">{APP_BRAND.descriptor}</span>
            </div>
        </>
    );
}

/**
 * The drawer's header block: the Ministry crest when the server supplies its
 * URL (brand.logo), else the shield-plus tile; then "Ministry of Health" and
 * "Trinidad & Tobago". In the icon rail only the mark shows. The crest is
 * decorative here: the text beside it names the Ministry.
 */
export function SidebarBrand({ logo }: { logo?: string | null }) {
    return (
        <span className="flex items-center gap-3">
            {logo ? (
                <img src={logo} alt="" className="is-drawer-close:size-8 h-10 w-auto object-contain" />
            ) : (
                <AppLogoMark className="is-drawer-close:size-8" />
            )}
            <span className="is-drawer-close:hidden grid gap-0.5">
                <span className="text-base-content text-sm leading-tight font-semibold">{APP_BRAND.descriptor}</span>
                <span className="text-base-content/60 text-xs leading-tight">{APP_BRAND.country}</span>
            </span>
        </span>
    );
}
