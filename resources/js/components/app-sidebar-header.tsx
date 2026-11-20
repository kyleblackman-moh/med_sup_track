import { APP_BRAND, AppLogoMark } from '@/components/app-logo';
import { useAppDrawer } from '@/components/app-shell';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Menu, PanelLeft } from 'lucide-react';

/**
 * The full-width top bar (Blueprint: daisyUI navbar; the Flowbite admin look):
 * white, bordered below, sticky above the drawer. It holds the drawer toggle
 * (opens the side on phones; switches the full menu and the icon rail from lg),
 * then the pill tile, the system name and the application name from the app
 * config (the shared `name` prop). The user menu lives at the foot of the
 * drawer. Hidden in print.
 */
export function AppSidebarHeader() {
    const { name } = usePage<SharedData>().props;
    const { id, open } = useAppDrawer();

    return (
        <header className="navbar bg-base-100 border-base-300 sticky top-0 z-30 min-h-16 gap-2 border-b px-3 py-0 lg:px-5 print:hidden">
            <label
                htmlFor={id}
                className="btn btn-ghost btn-square btn-sm motion-safe:transition-transform motion-safe:active:scale-95"
                aria-label={open ? 'Collapse navigation' : 'Expand navigation'}
            >
                <Menu className="size-5 lg:hidden" aria-hidden="true" />
                <PanelLeft className="hidden size-5 lg:block" aria-hidden="true" />
            </label>
            <Link href="/dashboard" prefetch className="rounded-field flex min-w-0 items-center gap-2 outline-none focus-visible:ring-2">
                <AppLogoMark />
                <span className="grid min-w-0 leading-tight">
                    <span className="truncate text-lg font-semibold capitalize">{APP_BRAND.name}</span>
                    {name !== APP_BRAND.name && (
                        <span data-app-title className="text-base-content/60 truncate text-xs">
                            {name}
                        </span>
                    )}
                </span>
            </Link>
        </header>
    );
}
