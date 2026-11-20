import { type RequestDocument } from '@/types/requests';
import { router } from '@inertiajs/react';
import { useReducer, useRef, useState } from 'react';

const PDF_ONLY = 'Only PDF files can be uploaded. Choose a PDF.';
const THROTTLED = 'Too many uploads in a short time. Wait a minute, then press Retry.';
const DID_NOT_FINISH = 'The upload did not finish. Check your connection and press Retry.';
const NOT_ACCEPTED = 'This file was not accepted. Choose another file.';

/**
 * One file the user chose that is not (yet) a document of the case. Once the
 * server has it, the item goes and the file is listed with the case's
 * documents (Checking, then Ready).
 */
export type UploadState =
    | { kind: 'waiting' }
    | { kind: 'uploading'; percent: number }
    /** `resend`: Retry sends the same file again (a dropped connection, too many uploads); otherwise Retry asks for another file. */
    | { kind: 'failed'; message: string; resend: boolean };

export interface UploadItem {
    key: string;
    file: File;
    /** The document this file replaces (a new version of it). */
    replaces: RequestDocument | null;
    state: UploadState;
}

type Outcome = { outcome: 'uploaded' } | { outcome: 'throttled' } | { outcome: 'failed' | 'rejected'; message: string };

const isPdf = (file: File) => file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');

let nextKey = 0;

interface Options {
    caseId: string;
    purpose: string;
    /** The server's limit (UploadRules.max_size_kb), checked here only to save a pointless upload. */
    maxBytes: number;
    /** Props reloaded after each upload (the documents, and the form's sections). */
    reloadProps: string[];
    /** True while files are being sent: the page pauses its scan polling. */
    onBusyChange: (busy: boolean) => void;
}

/**
 * FORM-COMPACT-1 (3): a category's uploads. Choosing files STARTS the upload:
 * one Inertia request per file (as the server expects), one file at a time,
 * with Inertia's own progress events. The server stays the authority on type,
 * size, scan, permissions and the upload throttle; the PDF and size checks
 * here only spare a pointless upload. No fetch, no axios, no storage.
 */
export function useDocumentUploads({ caseId, purpose, maxBytes, reloadProps, onBusyChange }: Options) {
    const queue = useRef<UploadItem[]>([]);
    const [, rerender] = useReducer((n: number) => n + 1, 0);
    const [announcement, setAnnouncement] = useState('');
    const running = useRef(false);
    const maxMb = Math.round(maxBytes / (1024 * 1024));

    const update = (change: (items: UploadItem[]) => UploadItem[]) => {
        queue.current = change(queue.current);
        rerender();
    };
    const setState = (key: string, state: UploadState) => update((items) => items.map((item) => (item.key === key ? { ...item, state } : item)));
    const drop = (key: string) => update((items) => items.filter((item) => item.key !== key));

    const send = (item: UploadItem) =>
        new Promise<Outcome>((resolve) => {
            let result: Outcome = { outcome: 'failed', message: DID_NOT_FINISH };
            // A 429 is not an Inertia response: stop Inertia's error page and stop the queue instead.
            const stopListening = router.on('invalid', (event) => {
                if (event.detail.response.status === 429) {
                    event.preventDefault();
                    result = { outcome: 'throttled' };
                }
            });

            router.post(
                route('requests.documents.store', caseId),
                { purpose, file: item.file, supersedes_id: item.replaces?.id ?? null },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    preserveState: true,
                    async: true, // the scan poll and the autosave must not cancel an upload
                    only: [...reloadProps, 'flash'],
                    onProgress: (event) => setState(item.key, { kind: 'uploading', percent: Math.round(event?.percentage ?? 0) }),
                    onSuccess: (page) => {
                        const flash = (page.props as { flash?: { error?: string | null } }).flash;
                        result = flash?.error ? { outcome: 'rejected', message: flash.error } : { outcome: 'uploaded' };
                    },
                    onError: (errors) => {
                        result = { outcome: 'rejected', message: errors.file ?? errors.purpose ?? errors.supersedes_id ?? NOT_ACCEPTED };
                    },
                    onFinish: () => {
                        stopListening();
                        resolve(result);
                    },
                },
            );
        });

    /** Sends every waiting file, one at a time. Stops at the throttle; the rest wait for Retry. */
    const run = async () => {
        if (running.current) {
            return;
        }
        running.current = true;
        onBusyChange(true);

        for (;;) {
            const item = queue.current.find((candidate) => candidate.state.kind === 'waiting');
            if (!item) {
                break;
            }
            setState(item.key, { kind: 'uploading', percent: 0 });
            setAnnouncement(`Uploading ${item.file.name}.`);
            const result = await send(item);

            if (result.outcome === 'uploaded') {
                drop(item.key); // now listed with the case's documents, as Checking
                setAnnouncement(`${item.file.name} uploaded. Checking it for viruses.`);
                continue;
            }
            if (result.outcome === 'throttled') {
                setState(item.key, { kind: 'failed', message: THROTTLED, resend: true });
                setAnnouncement(`${item.file.name} was not uploaded. ${THROTTLED}`);
                break;
            }
            setState(item.key, { kind: 'failed', message: result.message, resend: result.outcome === 'failed' });
            setAnnouncement(`${item.file.name} was not uploaded. ${result.message}`);
        }

        running.current = false;
        onBusyChange(false);
    };

    /** Adds the chosen files and starts sending them. A file already listed is not added twice. */
    const choose = (files: File[], replaces: RequestDocument | null, listed: RequestDocument[]): string | null => {
        const known = [...queue.current.map((item) => item.file), ...listed.map((doc) => ({ name: doc.display_name, size: doc.size_bytes }))];
        const added: UploadItem[] = [];
        const duplicates: string[] = [];

        for (const file of files) {
            if (
                replaces === null &&
                [...known, ...added.map((item) => item.file)].some((other) => other.name === file.name && other.size === file.size)
            ) {
                duplicates.push(file.name);
                continue;
            }
            const state: UploadState = !isPdf(file)
                ? { kind: 'failed', message: PDF_ONLY, resend: false }
                : file.size > maxBytes
                  ? { kind: 'failed', message: `This file is larger than ${maxMb} MB. Choose a smaller PDF.`, resend: false }
                  : { kind: 'waiting' };
            added.push({ key: `u${nextKey++}`, file, replaces, state });
        }

        if (added.length > 0) {
            update((items) => [...items, ...added]);
            void run();
        }

        return duplicates.length > 0 ? `Already listed, so not added again: ${duplicates.join(', ')}.` : null;
    };

    /** Retry a file that failed to send: the same file again. */
    const resend = (key: string) => {
        setState(key, { kind: 'waiting' });
        void run();
    };

    return { items: queue.current, announcement, setAnnouncement, choose, resend, dismiss: drop };
}
