import { AppShell, useAppDrawer } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { BrandStripe } from '@/components/brand-stripe';
import { type BreadcrumbItem } from '@/types';

interface LayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

/**
 * The daisyUI drawer (Blueprint: drawer + menu + navbar) in one place: the
 * toggle, the page area (brand stripe, top bar, page, full width on the
 * base-200 canvas) and the side with its overlay. A skip link jumps past the
 * navigation to the page.
 */
function Drawer({ children, breadcrumbs = [] }: LayoutProps) {
    const { open, setOpen, id } = useAppDrawer();

    return (
        <div className="drawer lg:drawer-open bg-base-200 min-h-svh">
            <input
                id={id}
                type="checkbox"
                className="drawer-toggle"
                aria-label="Show navigation"
                checked={open}
                onChange={(e) => setOpen(e.target.checked)}
            />
            <div className="drawer-content flex min-w-0 flex-col">
                <a href="#main-content" className="btn btn-primary btn-sm sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50">
                    Skip to content
                </a>
                <BrandStripe />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <main id="main-content" tabIndex={-1} className="flex min-w-0 flex-1 flex-col outline-none">
                    {children}
                </main>
            </div>
            <div className="drawer-side is-drawer-close:overflow-visible z-30 print:hidden">
                <label htmlFor={id} aria-label="Close navigation" className="drawer-overlay" />
                <AppSidebar />
            </div>
        </div>
    );
}

export default function AppSidebarLayout({ children, breadcrumbs = [] }: LayoutProps) {
    return (
        <AppShell>
            <Drawer breadcrumbs={breadcrumbs}>{children}</Drawer>
        </AppShell>
    );
}
