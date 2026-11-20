import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Link } from '@inertiajs/react';
import { Home } from 'lucide-react';
import { Fragment } from 'react';

/**
 * V2-4 (design.md section 6): the breadcrumb row on the workspace, below the
 * header: a home icon, then 14px crumbs; links in --primary 600, the current
 * crumb muted. The page decides the crumbs (the last is the request
 * reference, or "New request", never a document title).
 */
export function Breadcrumbs({ breadcrumbs }: { breadcrumbs: BreadcrumbItemType[] }) {
    if (breadcrumbs.length === 0) {
        return null;
    }

    return (
        <Breadcrumb>
            <BreadcrumbList className="text-sm">
                <BreadcrumbItem>
                    <BreadcrumbLink asChild className="text-primary hover:text-primary-strong rounded-sm">
                        <Link href="/dashboard" prefetch>
                            <Home className="size-[18px]" aria-hidden="true" />
                            <span className="sr-only">Home</span>
                        </Link>
                    </BreadcrumbLink>
                </BreadcrumbItem>
                <BreadcrumbSeparator />
                {breadcrumbs.map((item, index) => {
                    const isLast = index === breadcrumbs.length - 1;
                    return (
                        <Fragment key={index}>
                            <BreadcrumbItem>
                                {isLast ? (
                                    <BreadcrumbPage className="text-muted-foreground">{item.title}</BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink asChild className="text-primary hover:text-primary-strong font-semibold hover:underline">
                                        <Link href={item.href} prefetch>
                                            {item.title}
                                        </Link>
                                    </BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                            {!isLast && <BreadcrumbSeparator />}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
