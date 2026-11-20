import { useAppDrawer } from '@/components/app-shell';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { PanelLeft } from 'lucide-react';

/**
 * Top bar of the page area (daisyUI navbar): the drawer toggle, which pins or
 * collapses the side on desktop and opens it on phones, then the breadcrumbs.
 * Hidden in print.
 */
export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { id, open } = useAppDrawer();

    return (
        <div className="navbar bg-base-100 border-base-300 min-h-12 gap-2 border-b px-2 py-0 lg:px-4 print:hidden">
            <label
                htmlFor={id}
                className="btn btn-ghost btn-square btn-sm transition-transform active:scale-95"
                aria-label={open ? 'Collapse navigation' : 'Expand navigation'}
            >
                <PanelLeft className="size-4" aria-hidden="true" />
            </label>
            <div className="min-w-0 text-xs capitalize">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </div>
    );
}
