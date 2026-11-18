import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { type BreadcrumbItem } from '@/types';

/**
 * V2-4 app shell (design.md sections 5 and 6): the navy sidebar on the left,
 * the white application header across the top of the workspace, and the page
 * directly on the light blue-grey workspace (no canvas card, no brand
 * stripe). Detail and form pages (two crumbs or more) get the breadcrumb row
 * under the header. A skip link jumps past the navigation and the header.
 */
export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    return (
        <AppShell variant="sidebar">
            <a
                href="#main-content"
                className="bg-primary text-primary-foreground focus-visible:ring-ring sr-only z-50 rounded-lg px-3 py-2 text-sm font-medium outline-none focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus-visible:ring-2 focus-visible:ring-offset-2"
            >
                Skip to content
            </a>
            <AppSidebar />
            <div className="bg-background flex min-h-svh min-w-0 flex-1 flex-col">
                <AppSidebarHeader />
                <AppContent variant="sidebar" id="main-content" tabIndex={-1}>
                    {breadcrumbs.length > 1 && (
                        <div className="px-4 pt-4 md:px-6 md:pt-5 print:hidden">
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        </div>
                    )}
                    {children}
                </AppContent>
            </div>
        </AppShell>
    );
}
