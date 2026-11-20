import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';

/** The signed-in user at the foot of the drawer; opens Settings and Log out. */
export function NavUser() {
    const { auth } = usePage<SharedData>().props;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="btn btn-ghost is-drawer-close:justify-center is-drawer-close:px-0 is-drawer-close:[&>.grid]:hidden h-auto w-full justify-start gap-2 px-2 py-2 font-normal"
                    aria-label={`Account menu for ${auth.user.name}`}
                >
                    <UserInfo user={auth.user} />
                    <ChevronsUpDown className="is-drawer-close:hidden ml-auto size-4" aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="min-w-56" align="start" side="top">
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
