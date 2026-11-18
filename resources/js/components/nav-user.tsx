import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { useIsMobile } from '@/hooks/use-mobile';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, LogOut } from 'lucide-react';

/**
 * V2-4: the sidebar footer (design.md section 6). The user card on
 * --navy-deep: initials avatar, name 14px/600 and the server's subtitle
 * (organisation for vendors, role name for staff) 12px --navy-muted, with a
 * chevron that opens the existing profile menu. Below it a Log out row on the
 * existing logout route.
 */
export function NavUser() {
    const { auth } = usePage<SharedData>().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const getInitials = useInitials();
    const cleanup = useMobileNavigation();

    return (
        <SidebarMenu className="gap-1">
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            tooltip={auth.user.name}
                            className="bg-navy-deep data-[state=open]:bg-sidebar-accent h-auto min-h-14 gap-2.5 px-2.5 py-2 group-data-[collapsible=icon]:min-h-0"
                        >
                            <Avatar className="size-8 shrink-0 overflow-hidden rounded-full">
                                <AvatarFallback className="bg-ring-wash text-primary-strong rounded-full text-[11px] font-semibold">
                                    {getInitials(auth.user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="grid min-w-0 flex-1 text-left leading-tight group-data-[collapsible=icon]:hidden">
                                <span className="text-navy-foreground truncate text-sm font-semibold">{auth.user.name}</span>
                                {auth.user.subtitle && <span className="text-navy-muted truncate text-xs font-normal">{auth.user.subtitle}</span>}
                            </span>
                            <ChevronDown className="text-navy-muted ml-auto size-4 group-data-[collapsible=icon]:hidden" aria-hidden="true" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="end"
                        side={isMobile ? 'bottom' : state === 'collapsed' ? 'left' : 'bottom'}
                    >
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
            <SidebarMenuItem>
                <SidebarMenuButton asChild tooltip="Log out" className="h-11 gap-3 px-3 [&>svg]:size-5">
                    <Link method="post" href={route('logout')} as="button" onClick={cleanup}>
                        <LogOut aria-hidden="true" />
                        <span>Log out</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
