import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * UI-4: the top row of the L1 canvas, in flow (not sticky): the sidebar toggle
 * (always reachable, also below md) and the 12px muted breadcrumb (design.md
 * section 6). The page's own header (PageHeader: title, subtitle, actions)
 * follows inside the page. Hidden in print.
 */
export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        <div className="flex min-h-12 shrink-0 items-center gap-2 px-4 pt-3 text-xs md:px-6 print:hidden">
            <SidebarTrigger className="-ml-1" />
            <Breadcrumbs breadcrumbs={breadcrumbs} />
        </div>
    );
}
