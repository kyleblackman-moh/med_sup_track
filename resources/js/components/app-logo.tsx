import { ShieldPlus } from 'lucide-react';

/** The system name and descriptor shown next to the logo. Change them here only. */
export const APP_BRAND = {
    name: 'Medication Procurement',
    descriptor: 'Ministry of Health',
} as const;

export default function AppLogo() {
    return (
        <>
            {/* design.md section 6: a 34px --primary tile, corners 4px except one 16px bottom-left. V2-2c: the shield-plus mark. */}
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-[34px] shrink-0 items-center justify-center rounded-[4px] rounded-bl-2xl">
                <ShieldPlus className="size-5" aria-hidden="true" />
            </div>
            {/* V2-2c: the system name may wrap to two lines rather than being cut off. */}
            <div className="ml-1 grid flex-1 gap-0.5 text-left text-sm">
                <span className="leading-tight font-semibold">{APP_BRAND.name}</span>
                <span className="text-muted-foreground text-xs leading-tight">{APP_BRAND.descriptor}</span>
            </div>
        </>
    );
}
