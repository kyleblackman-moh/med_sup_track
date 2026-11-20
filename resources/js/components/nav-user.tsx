import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, LogOut } from 'lucide-react';

/**
 * The drawer footer: the user card (initials, name and the server's subtitle:
 * organisation for vendors, role name for staff) opening the profile menu,
 * then a Log Out row on the existing logout route.
 */
export function NavUser() {
    const { auth } = usePage<SharedData>().props;
    const getInitials = useInitials();
    const cleanup = useMobileNavigation();

    return (
        <div className="flex flex-col gap-1">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        className="btn btn-ghost is-drawer-close:justify-center is-drawer-close:px-0 h-auto min-h-12 w-full justify-start gap-2.5 px-2 py-2 font-normal"
                        aria-label={`Account menu for ${auth.user.name}`}
                    >
                        <Avatar className="size-8 shrink-0 overflow-hidden rounded-full">
                            <AvatarFallback className="bg-base-300 text-base-content rounded-full text-[11px] font-semibold">
                                {getInitials(auth.user.name)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="is-drawer-close:hidden grid min-w-0 flex-1 text-left leading-tight">
                            <span className="truncate text-sm font-semibold">{auth.user.name}</span>
                            {auth.user.subtitle && <span className="text-base-content/60 truncate text-xs">{auth.user.subtitle}</span>}
                        </span>
                        <ChevronDown className="text-base-content/60 is-drawer-close:hidden ml-auto size-4" aria-hidden="true" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent className="min-w-56" align="start" side="top">
                    <UserMenuContent user={auth.user} />
                </DropdownMenuContent>
            </DropdownMenu>
            <ul className="menu w-full p-0 capitalize">
                <li>
                    <Link
                        method="post"
                        href={route('logout')}
                        as="button"
                        onClick={cleanup}
                        className="is-drawer-close:tooltip is-drawer-close:tooltip-right"
                        data-tip="Log Out"
                    >
                        <LogOut className="size-4 shrink-0" aria-hidden="true" />
                        <span className="is-drawer-close:hidden">Log Out</span>
                    </Link>
                </li>
            </ul>
        </div>
    );
}
