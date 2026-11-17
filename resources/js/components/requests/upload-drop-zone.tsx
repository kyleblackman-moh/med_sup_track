import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type DocumentGroup, type RequestDocument, type UploadRules } from '@/types/requests';
import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { forwardRef, useImperativeHandle, useRef, useState, type DragEvent } from 'react';

const PDF_ONLY = 'Only PDF files are accepted.';
const THROTTLED = 'Too many uploads. Wait a minute, then press Upload again.';

type ItemState =
    | { kind: 'waiting' }
    | { kind: 'uploading'; percent: number }
    | { kind: 'invalid'; message: string } // caught before upload; never sent
    | { kind: 'rejected'; message: string }; // refused by the server

interface QueueItem {
    key: string;
    file: File;
    /** Set when this file replaces an uploaded one (a new version). */
    replaces: RequestDocument | null;
    state: ItemState;
}

type Outcome = 'uploaded' | 'rejected' | 'throttled' | 'failed';

export interface UploadDropZoneHandle {
    /** Opens the file chooser for one file that will replace `doc`. */
    replace: (doc: RequestDocument) => void;
}

interface Props {
    caseId: string;
    group: DocumentGroup;
    rules?: UploadRules;
    /** Props reloaded after each upload (the page's documents and readiness or sections). */
    reloadProps: string[];
    /** True while this zone is sending files: the page pauses its scan polling. */
    onBusyChange: (busy: boolean) => void;
}

export function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const isPdf = (file: File) => file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');

let nextKey = 0;

/**
 * UI-3: one category's drop zone. Choosing or dropping files only adds them to
 * the waiting list; Upload sends them one at a time (Inertia, one request per
 * file, as the server expects). The server still validates every file; the
 * checks here only save a pointless upload. Drop is an enhancement: the Choose
 * files button and its hidden input do everything from the keyboard.
 */
export const UploadDropZone = forwardRef<UploadDropZoneHandle, Props>(function UploadDropZone(
    { caseId, group, rules, reloadProps, onBusyChange },
    ref,
) {
    const [queue, setQueue] = useState<QueueItem[]>([]);
    const [note, setNote] = useState<string | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const [dragging, setDragging] = useState(false);
    const [busy, setBusy] = useState(false);
    const dragDepth = useRef(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const replaceInputRef = useRef<HTMLInputElement>(null);
    const replacing = useRef<RequestDocument | null>(null);
    const uploadButtonRef = useRef<HTMLButtonElement>(null);
    const queueRef = useRef(queue);
    queueRef.current = queue;

    const id = `upload-${group.purpose}`;
    const maxBytes = (rules?.max_size_kb ?? 25600) * 1024;
    const maxMb = Math.round(maxBytes / (1024 * 1024));
    const sendable = queue.filter((item) => item.state.kind === 'waiting');

    useImperativeHandle(ref, () => ({
        replace: (doc) => {
            replacing.current = doc;
            replaceInputRef.current?.click();
        },
    }));

    const setItem = (key: string, state: ItemState) => setQueue((items) => items.map((item) => (item.key === key ? { ...item, state } : item)));

    const add = (files: File[], replaces: RequestDocument | null = null) => {
        const known = [
            ...queueRef.current.map((item) => item.file),
            ...group.documents.map((doc) => ({ name: doc.display_name, size: doc.size_bytes })),
        ];
        const added: QueueItem[] = [];
        const duplicates: string[] = [];

        for (const file of files) {
            const duplicate = [...known, ...added.map((item) => item.file)].some((other) => other.name === file.name && other.size === file.size);
            if (duplicate && replaces === null) {
                duplicates.push(file.name);
                continue;
            }
            const state: ItemState = !isPdf(file)
                ? { kind: 'invalid', message: PDF_ONLY }
                : file.size > maxBytes
                  ? { kind: 'invalid', message: `This file is larger than ${maxMb} MB.` }
                  : { kind: 'waiting' };
            added.push({ key: `f${nextKey++}`, file, replaces, state });
        }

        if (added.length > 0) {
            setQueue((items) => [...items, ...added]);
        }
        setNote(duplicates.length > 0 ? `Already in the list, so not added again: ${duplicates.join(', ')}.` : null);
        setAnnouncement(
            [
                added.length > 0 ? `${added.length} ${added.length === 1 ? 'file' : 'files'} added.` : '',
                duplicates.length > 0 ? `${duplicates.length} skipped as duplicates.` : '',
            ]
                .filter(Boolean)
                .join(' '),
        );
    };

    const remove = (key: string) => setQueue((items) => items.filter((item) => item.key !== key));

    /** One file, one request. Resolves when the request has finished, whatever happened. */
    const send = (item: QueueItem) =>
        new Promise<{ outcome: Outcome; message?: string }>((resolve) => {
            let result: { outcome: Outcome; message?: string } = { outcome: 'failed', message: 'The upload did not finish. Try again.' };
            // A 429 is not an Inertia response: stop Inertia's error page and stop the batch instead.
            const stopListening = router.on('invalid', (event) => {
                if (event.detail.response.status === 429) {
                    event.preventDefault();
                    result = { outcome: 'throttled' };
                }
            });

            router.post(
                route('requests.documents.store', caseId),
                { purpose: group.purpose, file: item.file, supersedes_id: item.replaces?.id ?? null },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    preserveState: true,
                    async: true, // the scan poll and autosave must not cancel an upload
                    only: [...reloadProps, 'flash'],
                    onProgress: (event) => setItem(item.key, { kind: 'uploading', percent: Math.round(event?.percentage ?? 0) }),
                    onSuccess: (page) => {
                        const flash = (page.props as { flash?: { error?: string | null } }).flash;
                        result = flash?.error ? { outcome: 'rejected', message: flash.error } : { outcome: 'uploaded' };
                    },
                    onError: (errors) => {
                        result = {
                            outcome: 'rejected',
                            message: errors.file ?? errors.purpose ?? errors.supersedes_id ?? 'The file was not accepted.',
                        };
                    },
                    onFinish: () => {
                        stopListening();
                        resolve(result);
                    },
                },
            );
        });

    const uploadAll = async () => {
        const batch = queueRef.current.filter((item) => item.state.kind === 'waiting');
        if (batch.length === 0 || busy) {
            return;
        }
        setBusy(true);
        onBusyChange(true);
        setNote(null);
        let uploaded = 0;
        let failed = 0;

        for (const [i, item] of batch.entries()) {
            setAnnouncement(`Uploading ${i + 1} of ${batch.length}: ${item.file.name}.`);
            setItem(item.key, { kind: 'uploading', percent: 0 });
            const { outcome, message } = await send(item);

            if (outcome === 'uploaded') {
                uploaded++;
                remove(item.key); // now listed under the uploaded files, as Scanning
                continue;
            }
            if (outcome === 'throttled') {
                setItem(item.key, { kind: 'waiting' }); // this one and the rest stay queued
                setNote(THROTTLED);
                setAnnouncement(THROTTLED);
                break;
            }
            failed++;
            setItem(item.key, { kind: 'rejected', message: message ?? 'The file was not accepted.' });
            setAnnouncement(`${item.file.name} was not uploaded: ${message ?? 'the file was not accepted.'}`);
        }

        setBusy(false);
        onBusyChange(false);
        if (uploaded + failed > 0) {
            setAnnouncement(
                [`${uploaded} ${uploaded === 1 ? 'file' : 'files'} uploaded.`, failed > 0 ? `${failed} not uploaded.` : ''].filter(Boolean).join(' '),
            );
        }
        window.setTimeout(() => uploadButtonRef.current?.focus(), 0);
    };

    const onDragEnter = (e: DragEvent) => {
        e.preventDefault();
        dragDepth.current += 1;
        setDragging(true);
    };
    const onDragLeave = (e: DragEvent) => {
        e.preventDefault();
        dragDepth.current = Math.max(0, dragDepth.current - 1);
        if (dragDepth.current === 0) {
            setDragging(false);
        }
    };
    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        dragDepth.current = 0;
        setDragging(false);
        if (!busy) {
            add(Array.from(e.dataTransfer.files));
        }
    };

    const chosenText = queue.length === 0 ? 'No files chosen' : `${queue.length} ${queue.length === 1 ? 'file' : 'files'} chosen`;

    return (
        <div className="mt-4 grid gap-3">
            <div
                role="group"
                aria-labelledby={`${id}-title`}
                aria-describedby={`${id}-rules`}
                onDragEnter={onDragEnter}
                onDragOver={(e) => e.preventDefault()}
                onDragLeave={onDragLeave}
                onDrop={onDrop}
                className={cn(
                    'grid gap-3 rounded-md border-2 border-dashed p-4 transition-colors motion-reduce:transition-none',
                    dragging ? 'border-primary bg-accent' : 'border-border',
                )}
            >
                <p className="text-sm">Drag and drop PDF files here, or</p>
                <p id={`${id}-rules`} className="sr-only">
                    PDF only, up to {maxMb} MB each.
                </p>
                <div className="flex flex-wrap items-center gap-2">
                    {/* The hidden inputs are the real file controls; the buttons open them. */}
                    <input
                        ref={inputRef}
                        id={id}
                        type="file"
                        multiple
                        accept="application/pdf,.pdf"
                        className="sr-only"
                        tabIndex={-1}
                        aria-hidden
                        onChange={(e) => {
                            add(Array.from(e.target.files ?? []));
                            e.target.value = ''; // so choosing the same files again fires onChange
                        }}
                    />
                    <input
                        ref={replaceInputRef}
                        type="file"
                        accept="application/pdf,.pdf"
                        className="sr-only"
                        tabIndex={-1}
                        aria-hidden
                        onChange={(e) => {
                            add(Array.from(e.target.files ?? []).slice(0, 1), replacing.current);
                            replacing.current = null;
                            e.target.value = '';
                        }}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-11 md:h-9"
                        onClick={() => inputRef.current?.click()}
                        disabled={busy}
                    >
                        Choose files
                    </Button>
                    <Button
                        ref={uploadButtonRef}
                        type="button"
                        size="sm"
                        className="h-11 md:h-9"
                        onClick={() => void uploadAll()}
                        disabled={busy || sendable.length === 0}
                    >
                        {busy ? 'Uploading…' : 'Upload'}
                    </Button>
                    <span className="text-muted-foreground text-sm">{chosenText}</span>
                </div>
            </div>

            {note && <p className="text-muted-foreground text-sm">{note}</p>}

            {queue.length > 0 && (
                <ul className="grid gap-2" aria-label={`Files waiting to upload: ${group.label}`}>
                    {queue.map((item) => (
                        <li key={item.key} className="rounded-md border p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium break-all">{item.file.name}</p>
                                    <p className="text-muted-foreground text-xs">
                                        {formatSize(item.file.size)}
                                        {item.replaces && ` · replaces ${item.replaces.display_name}`}
                                        {' · '}
                                        <ItemStatus state={item.state} />
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-11 md:size-9"
                                    aria-label={`Remove ${item.file.name}`}
                                    onClick={() => remove(item.key)}
                                    disabled={item.state.kind === 'uploading'}
                                >
                                    <X className="size-4" aria-hidden />
                                </Button>
                            </div>
                            {item.state.kind === 'uploading' && (
                                <progress value={item.state.percent} max={100} aria-hidden className="mt-2 h-2 w-full" />
                            )}
                            {(item.state.kind === 'invalid' || item.state.kind === 'rejected') && (
                                <p className="text-destructive mt-1 text-sm">{item.state.message}</p>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <p aria-live="polite" className="sr-only">
                {announcement}
            </p>
        </div>
    );
});

function ItemStatus({ state }: { state: ItemState }) {
    switch (state.kind) {
        case 'waiting':
            return <span>Waiting</span>;
        case 'uploading':
            return <span className="tabular-nums">Uploading {state.percent}%</span>;
        case 'invalid':
            return <span className="text-destructive">Not uploaded</span>;
        case 'rejected':
            return <span className="text-destructive">Rejected</span>;
    }
}
