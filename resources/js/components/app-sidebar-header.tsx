import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';

/**
 * V2-4: the white application header (design.md sections 5 and 6): 72px, a
 * 1px border and a soft shadow. Left: the sidebar toggle (also the way to
 * open the off-canvas sidebar below md), then the application name from the
 * app config (the shared `name` prop), 22px/700. Right: the user menu. No
 * notifications control until notifications exist (Phase 2). Hidden in print.
 */
export function AppSidebarHeader() {
    const { name, auth } = usePage<SharedData>().props;
    const getInitials = useInitials();

    return (
        <header className="bg-header text-header-foreground flex h-18 shrink-0 items-center gap-3 border-b px-4 shadow-xs md:px-6 print:hidden">
            <SidebarTrigger className="-ml-1 size-10 shrink-0 [&_svg]:size-5" />
            <p data-app-title className="min-w-0 truncate text-[22px] leading-tight font-bold">
                {name}
            </p>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={`Account menu, ${auth.user.name}`}
                        className="hover:bg-muted focus-visible:ring-ring data-[state=open]:bg-muted ml-auto flex h-11 shrink-0 items-center gap-2 rounded-full py-0.5 pr-2 pl-0.5 outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                    >
                        <Avatar className="size-10 overflow-hidden rounded-full">
                            <AvatarFallback className="bg-ring-wash text-primary-strong rounded-full text-sm font-semibold">
                                {getInitials(auth.user.name)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="hidden max-w-48 truncate text-sm font-medium sm:inline">{auth.user.name}</span>
                        <ChevronDown className="text-muted-foreground size-4" aria-hidden="true" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent className="w-56" align="end">
                    <UserMenuContent user={auth.user} />
                </DropdownMenuContent>
            </DropdownMenu>
        </header>
    );
}
