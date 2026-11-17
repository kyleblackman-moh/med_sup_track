import AppLogoIcon from './app-logo-icon';

/** The system name and descriptor shown next to the logo. Change them here only. */
export const APP_BRAND = {
    name: 'Medication Procurement',
    descriptor: 'Ministry of Health',
} as const;

export default function AppLogo() {
    return (
        <>
            {/* design.md section 6: a 34px --primary tile, corners 4px except one 16px bottom-left. */}
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-[34px] shrink-0 items-center justify-center rounded-[4px] rounded-bl-2xl">
                <AppLogoIcon className="size-5 fill-current" aria-hidden="true" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">{APP_BRAND.name}</span>
                <span className="text-muted-foreground truncate text-xs leading-none">{APP_BRAND.descriptor}</span>
            </div>
        </>
    );
}
