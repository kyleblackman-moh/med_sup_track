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
 * V2-4: the navy sidebar's header block (design.md section 6): the shield-plus
 * tile, "Ministry of Health" 16px/600 white and "Trinidad & Tobago" 13px
 * --navy-muted, centred. Collapsed to icons, only the mark shows.
 */
export function SidebarBrand() {
    return (
        <span className="flex flex-col items-center gap-2 text-center">
            <AppLogoMark className="bg-nav-active text-nav-active-foreground size-11 group-data-[collapsible=icon]:size-8" />
            <span className="grid gap-0.5 group-data-[collapsible=icon]:hidden">
                <span className="text-navy-foreground text-base leading-tight font-semibold">{APP_BRAND.descriptor}</span>
                <span className="text-navy-muted text-[13px] leading-tight">{APP_BRAND.country}</span>
            </span>
        </span>
    );
}
