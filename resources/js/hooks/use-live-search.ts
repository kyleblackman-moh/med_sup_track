import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface Options {
    /** The list's URL, without the query. */
    url: string;
    /** The search the server applied (from the page props), so the box follows back/forward. */
    applied: string;
    /** Props to reload: the list and its filters only. */
    only: string[];
    /** The URL parameter that carries the search. */
    param?: string;
    delayMs?: number;
    maxLength?: number;
}

/**
 * UI-3: a search box that filters through the server as you type. Debounced,
 * kept in the URL, and the page state and scroll position are preserved.
 * Returns the box's value and its change handler.
 */
export function useLiveSearch({ url, applied, only, param = 'q', delayMs = 300, maxLength = 100 }: Options) {
    const [value, setValue] = useState(applied);
    const onlyKey = only.join(',');
    const sent = useRef(applied);

    // The server's value wins after a visit (back/forward, or a trimmed or cut search).
    useEffect(() => {
        sent.current = applied;
        setValue((current) => (current.trim() === applied ? current : applied));
    }, [applied]);

    useEffect(() => {
        const query = value.trim().slice(0, maxLength);
        if (query === sent.current) {
            return;
        }
        const timer = window.setTimeout(() => {
            sent.current = query;
            router.get(url, query === '' ? {} : { [param]: query }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: onlyKey.split(','),
            });
        }, delayMs);

        return () => window.clearTimeout(timer);
    }, [value, url, param, delayMs, maxLength, onlyKey]);

    return { value, setValue };
}
