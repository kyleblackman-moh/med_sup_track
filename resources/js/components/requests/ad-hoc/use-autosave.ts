import { type Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/** Debounce after the last change, and the minimum gap between two saves (well under 30 a minute). */
export const DEBOUNCE_MS = 2000;
export const MIN_GAP_MS = 5000;

/** Props reloaded after a save: the saved figures, the section status and the new row ids. */
const RELOAD = ['case', 'request_sections', 'form', 'saved'];

/**
 * FORM-ERR-1 (D): "invalid" = the server refused the save for validation (the
 * fields are marked in red); "error" = a network or server failure.
 */
export type SaveStatus = 'idle' | 'saving' | 'saved' | 'invalid' | 'error';

interface Options {
    url: string;
    /** The full payload, read when the save starts (so it carries the latest edits). */
    payload: () => Record<string, unknown>;
    /** Called with the reloaded page after a successful save. */
    onSaved: (page: Page) => void;
}

/**
 * Autosave through the Inertia router (no fetch): one request in flight at a
 * time, DEBOUNCE_MS after the last change and at least MIN_GAP_MS between
 * requests. `async` so a document upload or reload never cancels it. A failed
 * save keeps the local data and reports "error"; the next change or retry()
 * tries again. flush() saves now (still respecting the gap) and resolves true
 * once everything changed so far is saved, false if that save failed.
 */
export function useAutosave({ url, payload, onSaved }: Options) {
    const [status, setStatus] = useState<SaveStatus>('idle');
    const [savedAt, setSavedAt] = useState<string | null>(null);

    const latest = useRef({ url, payload, onSaved });
    latest.current = { url, payload, onSaved };

    const engine = useRef<{
        version: number;
        savedVersion: number;
        inFlight: boolean;
        lastStart: number;
        timer: number | null;
        waiters: ((ok: boolean) => void)[];
        /** Set by stop(): the draft is being deleted, so nothing more is saved. */
        stopped: boolean;
    }>({ version: 0, savedVersion: 0, inFlight: false, lastStart: 0, timer: null, waiters: [], stopped: false });

    const api = useRef<{ run: () => void; schedule: (ms: number) => void } | null>(null);

    if (api.current === null) {
        const e = engine.current;
        const settle = (ok: boolean) => {
            const waiters = e.waiters;
            e.waiters = [];
            waiters.forEach((resolve) => resolve(ok));
        };
        const schedule = (ms: number) => {
            if (e.timer !== null) {
                window.clearTimeout(e.timer);
            }
            e.timer = window.setTimeout(run, ms);
        };
        const run = () => {
            e.timer = null;
            if (e.stopped) {
                settle(true);

                return;
            }
            if (e.inFlight) {
                return; // onFinish picks up whatever changed meanwhile
            }
            if (e.version === e.savedVersion) {
                settle(true);

                return;
            }
            const wait = e.lastStart + MIN_GAP_MS - Date.now();
            if (wait > 0) {
                schedule(wait);

                return;
            }

            const sent = e.version;
            let ok = false;
            let invalid = false;
            e.inFlight = true;
            e.lastStart = Date.now();
            setStatus('saving');

            router.put(latest.current.url, latest.current.payload() as never, {
                preserveState: true,
                preserveScroll: true,
                async: true,
                only: RELOAD,
                // Validation refused the save (the errors arrive as page props); not a network or server failure.
                onError: () => {
                    invalid = true;
                },
                onSuccess: (page) => {
                    ok = true;
                    e.savedVersion = Math.max(e.savedVersion, sent);
                    latest.current.onSaved(page);
                    const saved = (page.props as { saved?: { saved_at?: string } | null }).saved;
                    setSavedAt(saved?.saved_at ?? new Date().toISOString());
                },
                onFinish: () => {
                    e.inFlight = false;
                    if (!ok) {
                        setStatus(invalid ? 'invalid' : 'error');
                        settle(false);

                        return;
                    }
                    if (e.version > e.savedVersion) {
                        // Changed during the save: save again (now if someone is waiting).
                        if (e.waiters.length > 0) {
                            run();
                        } else {
                            schedule(DEBOUNCE_MS);
                        }

                        return;
                    }
                    setStatus('saved');
                    settle(true);
                },
            });
        };
        api.current = { run, schedule };
    }

    // Warn before leaving only while something is unsaved.
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            const e = engine.current;
            if (!e.stopped && (e.version > e.savedVersion || e.inFlight)) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, []);

    useEffect(
        () => () => {
            if (engine.current.timer !== null) {
                window.clearTimeout(engine.current.timer);
            }
        },
        [],
    );

    /** Call after every local change. */
    const changed = () => {
        if (engine.current.stopped) {
            return;
        }
        engine.current.version += 1;
        setStatus('saving');
        api.current?.schedule(DEBOUNCE_MS);
    };

    /** Save now (respecting the gap); resolves once everything changed so far is saved. */
    const flush = () =>
        new Promise<boolean>((resolve) => {
            const e = engine.current;
            if (e.version === e.savedVersion && !e.inFlight) {
                resolve(true);

                return;
            }
            e.waiters.push(resolve);
            if (!e.inFlight) {
                if (e.timer !== null) {
                    window.clearTimeout(e.timer);
                    e.timer = null;
                }
                api.current?.run();
            }
        });

    const retry = () => {
        void flush();
    };

    /** Stop for good: drop any pending save (the draft is being deleted). A save already sent finishes on its own. */
    const stop = () => {
        const e = engine.current;
        e.stopped = true;
        if (e.timer !== null) {
            window.clearTimeout(e.timer);
            e.timer = null;
        }
        e.savedVersion = e.version;
    };

    return { status, savedAt, changed, flush, retry, stop };
}
