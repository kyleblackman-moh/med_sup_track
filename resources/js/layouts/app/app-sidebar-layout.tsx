import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { BrandStripe } from '@/components/brand-stripe';
import { type BreadcrumbItem } from '@/types';

/**
 * UI-4 app shell (design.md sections 5 and 6): the sidebar sits on the L0
 * background; the page lives on the L1 canvas (white, bordered, rounded) with
 * the brand stripe on top and an in-flow top row (sidebar toggle and
 * breadcrumbs). A skip link jumps past the navigation to the canvas.
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
            <AppContent variant="sidebar" id="main-content" tabIndex={-1}>
                <BrandStripe />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
