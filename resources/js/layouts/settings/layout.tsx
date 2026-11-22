import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { KeyRound, ShieldCheck, UserRound } from 'lucide-react';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        url: '/settings/profile',
        icon: UserRound,
    },
    {
        title: 'Password',
        url: '/settings/password',
        icon: KeyRound,
    },
    {
        title: 'Security',
        url: '/settings/security',
        icon: ShieldCheck,
    },
    // Appearance is hidden: light only for launch (design.md section 1).
];

/**
 * Settings shell (Blueprint pages/profile-settings and pages/security-settings):
 * the page header, then a compact daisyUI menu of settings sections beside a
 * column of bordered cards (one card per task, each ending in its footer row).
 * The menu stacks above the cards below lg. Full width beside the drawer.
 */
export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = usePage().url.split('?')[0];

    return (
        <div className="w-full min-w-0 space-y-5 px-4 py-4 lg:px-6">
            <header className="space-y-1">
                <h1 className="text-xl font-semibold capitalize">Settings</h1>
                <p className="text-base-content/70 text-sm">Manage your profile and account settings</p>
            </header>

            <div className="grid gap-5 lg:grid-cols-[12rem_minmax(0,1fr)] lg:items-start">
                <nav aria-label="Settings sections">
                    <ul className="menu menu-sm bg-base-100 border-base-300 rounded-box w-full border">
                        {sidebarNavItems.map((item) => {
                            // The two-factor setup page belongs to Security.
                            const active = currentPath === item.url || (item.url === '/settings/security' && currentPath === '/settings/two-factor');
                            const Icon = item.icon;
                            return (
                                <li key={item.url}>
                                    <Link
                                        href={item.url}
                                        prefetch
                                        className={active ? 'menu-active capitalize' : 'capitalize'}
                                        aria-current={active ? 'page' : undefined}
                                    >
                                        {Icon && <Icon className="size-4" aria-hidden="true" />}
                                        {item.title}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                <div className="flex max-w-2xl min-w-0 flex-col gap-5">{children}</div>
            </div>
        </div>
    );
}
