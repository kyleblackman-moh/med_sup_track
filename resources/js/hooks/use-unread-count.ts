import { bellApi, isAuthStop } from '@/lib/notifications';
import { useCallback, useEffect, useRef, useState } from 'react';

export const POLL_MS = 60_000;

/**
 * NOTIF-3: the bell's unread count. Starts from the shared
 * `unreadNotificationsCount` prop (and follows it on every Inertia visit),
 * polls GET /notifications/unread-count every 60 seconds only while the tab is
 * visible, and refreshes at once when the window gets focus. A 401 or 419
 * stops polling for good (the session is over); any other error is ignored
 * and the next tick tries again.
 */
export function useUnreadCount(shared: number, fetchCount: () => Promise<number> = bellApi.unreadCount) {
    const [count, setCount] = useState(shared);
    const stopped = useRef(false);
    const fetchRef = useRef(fetchCount);
    fetchRef.current = fetchCount;

    // A page visit brings a fresh count from the server.
    useEffect(() => setCount(shared), [shared]);

    const refresh = useCallback(async () => {
        if (stopped.current) {
            return;
        }
        try {
            setCount(await fetchRef.current());
        } catch (error) {
            if (isAuthStop(error)) {
                stopped.current = true;
            }
            // Anything else: stay quiet and try again on the next tick.
        }
    }, []);

    useEffect(() => {
        let timer: number | undefined;

        const start = () => {
            if (timer === undefined && !stopped.current) {
                timer = window.setInterval(() => {
                    if (stopped.current) {
                        stop();
                        return;
                    }
                    void refresh();
                }, POLL_MS);
            }
        };
        const stop = () => {
            if (timer !== undefined) {
                window.clearInterval(timer);
                timer = undefined;
            }
        };
        const onVisibility = () => (document.visibilityState === 'visible' ? start() : stop());
        const onFocus = () => void refresh();

        onVisibility();
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('focus', onFocus);

        return () => {
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('focus', onFocus);
        };
    }, [refresh]);

    return { count, setCount, refresh };
}
