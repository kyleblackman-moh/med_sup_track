import { useAppDrawer } from '@/components/app-shell';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { PanelLeft } from 'lucide-react';

/**
 * Top bar of the page area (Blueprint: daisyUI navbar): the drawer toggle,
 * which collapses the side to an icon rail on desktop and opens it on phones,
 * then the application name from the app config (the shared `name` prop).
 * The user menu lives at the foot of the drawer. Hidden in print.
 */
export function AppSidebarHeader() {
    const { name } = usePage<SharedData>().props;
    const { id, open } = useAppDrawer();

    return (
        <header className="navbar bg-base-100 border-base-300 min-h-14 gap-2 border-b px-2 py-0 lg:px-4 print:hidden">
            <label
                htmlFor={id}
                className="btn btn-ghost btn-square btn-sm transition-transform active:scale-95"
                aria-label={open ? 'Collapse navigation' : 'Expand navigation'}
            >
                <PanelLeft className="size-5" aria-hidden="true" />
            </label>
            <p data-app-title className="min-w-0 truncate text-lg leading-tight font-semibold">
                {name}
            </p>
        </header>
    );
}
