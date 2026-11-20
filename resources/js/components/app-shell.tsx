import { router } from '@inertiajs/react';
import { createContext, useContext, useEffect, useState } from 'react';

/** Width (px) from which the drawer is pinned open beside the page (Tailwind lg). */
const PINNED_FROM = 1024;

const DRAWER_ID = 'app-drawer';

interface DrawerState {
    /** Desktop: expanded (true) or icon rail (false). Below lg: overlay open or closed. */
    open: boolean;
    setOpen: (open: boolean) => void;
    id: string;
}

const DrawerContext = createContext<DrawerState>({ open: true, setOpen: () => undefined, id: DRAWER_ID });

export function useAppDrawer(): DrawerState {
    return useContext(DrawerContext);
}

const isPinned = () => typeof window !== 'undefined' && window.innerWidth >= PINNED_FROM;

function storedOpen(): boolean {
    try {
        return localStorage.getItem('sidebar') !== 'false';
    } catch {
        return true;
    }
}

/**
 * Open state for the app drawer (the markup lives in the sidebar layout).
 * From lg the side stays open and the toggle switches between the full menu
 * and an icon rail (remembered per browser). Below lg the side is an overlay,
 * closed on load and after every navigation.
 */
export function AppShell({ children }: { children: React.ReactNode }) {
    const [open, setOpenState] = useState<boolean>(() => (isPinned() ? storedOpen() : false));

    const setOpen = (next: boolean) => {
        setOpenState(next);
        if (isPinned()) {
            try {
                localStorage.setItem('sidebar', String(next));
            } catch {
                // Storage can be blocked; the toggle still works for this page.
            }
        }
    };

    useEffect(
        () =>
            router.on('navigate', () => {
                if (!isPinned()) setOpenState(false);
            }),
        [],
    );

    return <DrawerContext.Provider value={{ open, setOpen, id: DRAWER_ID }}>{children}</DrawerContext.Provider>;
}
