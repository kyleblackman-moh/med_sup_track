import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Options {
    /** The list's URL, without the query. */
    url: string;
    /** The search the server applied (from the page props), so the box follows back/forward. */
    applied: string;
    /** The card filter the server applied ("" for none). */
    appliedGroup?: string;
    /** Props to reload: the list and its filters only. */
    only: string[];
    /** The URL parameter that carries the search. */
    param?: string;
    delayMs?: number;
    maxLength?: number;
}

type CancelToken = { cancel: () => void };

/**
 * A search box that filters through the server as you type (UI-3, tuned in
 * UI-4): trimmed, cut to maxLength, debounced 200 ms, kept in the URL with the
 * card filter (`group`). Each visit is a partial reload of `only`, with the
 * page state and scroll preserved and the history entry replaced; a newer
 * visit cancels the one in flight. `searching` is true while a visit runs, so
 * the page can fade the old rows instead of blanking them.
 */
export function useLiveSearch({ url, applied, appliedGroup = '', only, param = 'q', delayMs = 200, maxLength = 100 }: Options) {
    const [value, setValue] = useState(applied);
    const [searching, setSearching] = useState(false);
    const onlyKey = only.join(',');
    const sent = useRef(applied);
    const group = useRef(appliedGroup);
    const inFlight = useRef<CancelToken | null>(null);

    // The server's values win after a visit (back/forward, or a trimmed or cut search).
    useEffect(() => {
        sent.current = applied;
        setValue((current) => (current.trim() === applied ? current : applied));
    }, [applied]);

    useEffect(() => {
        group.current = appliedGroup;
    }, [appliedGroup]);

    const visit = useCallback(
        (query: string, nextGroup: string) => {
            inFlight.current?.cancel();
            sent.current = query;
            group.current = nextGroup;

            const params: Record<string, string> = {};
            if (query !== '') params[param] = query;
            if (nextGroup !== '') params.group = nextGroup;

            router.get(url, params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: onlyKey.split(','),
                onCancelToken: (token) => {
                    inFlight.current = token;
                },
                onStart: () => setSearching(true),
                onFinish: () => {
                    inFlight.current = null;
                    setSearching(false);
                },
            });
        },
        [url, param, onlyKey],
    );

    useEffect(() => {
        const query = value.trim().slice(0, maxLength);
        if (query === sent.current) {
            return;
        }
        const timer = window.setTimeout(() => visit(query, group.current), delayMs);

        return () => window.clearTimeout(timer);
    }, [value, delayMs, maxLength, visit]);

    /** Applies a card filter at once with the current search; the same group again clears it. */
    const toggleGroup = useCallback(
        (next: string) => visit(value.trim().slice(0, maxLength), group.current === next ? '' : next),
        [value, maxLength, visit],
    );

    return { value, setValue, searching, toggleGroup };
}
