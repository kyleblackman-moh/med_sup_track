import InputError from '@/components/input-error';
import { UploadProgress } from '@/components/requests/upload-progress';
import { useDocumentUploads, type UploadItem } from '@/components/requests/use-document-uploads';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { type DocumentGroup, type RequestDocument, type UploadRules } from '@/types/requests';
import { router, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, FileText, LoaderCircle, RotateCw, Upload, X } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';

interface Props {
    caseId: string;
    groups: DocumentGroup[];
    rules?: UploadRules;
    /** Read-only lists (Show page): no upload, replace or remove. */
    readOnly?: boolean;
    /** Props reloaded while a file is being scanned (the AD_HOC page reloads its sections). */
    reloadProps?: string[];
    /** Inside a page section that already has its own heading (AD_HOC form): no h2 of its own. */
    embedded?: boolean;
}

/** What a file's scan state reads as. "Ready" only once the server says the scan is clean (fail-closed). */
const STATUS_TEXT: Record<RequestDocument['status_label'], string> = {
    Scanning: 'Checking',
    Ready: 'Ready',
    Rejected: 'Not accepted',
};

const REJECTED_TEXT = "This file didn't pass the virus check. Choose another file to replace it.";

export function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * FORM-COMPACT-1 (3): supporting documents as a grid of compact cells, one
 * per category the server lists (three columns once the card is 48rem wide,
 * one below), each with its label, a "Choose File" button and its files. The
 * server decides what is listed and allowed (can_* flags) and validates
 * every upload; this only sends requests through Inertia. While anything is
 * being checked, the documents props are reloaded every 5 seconds, except
 * while files are being sent.
 */
export function SupportingDocuments({ caseId, groups, rules, readOnly = false, reloadProps = ['documents', 'readiness'], embedded = false }: Props) {
    const checking = groups.some((group) => group.documents.some((doc) => doc.status_label === 'Scanning'));
    const reloadKey = reloadProps.join(','); // a stable dependency (the default array is new on every render)
    const [busyCells, setBusyCells] = useState(0);
    const uploading = busyCells > 0;

    useEffect(() => {
        if (!checking || uploading) {
            return;
        }
        const timer = window.setInterval(() => router.reload({ only: reloadKey.split(',') }), 5000);

        return () => window.clearInterval(timer);
    }, [checking, uploading, reloadKey]);

    return (
        <section aria-labelledby="documents-heading" className="grid gap-3">
            <div>
                <h2 id="documents-heading" className={embedded ? 'sr-only' : 'text-lg font-semibold'}>
                    Supporting Documents
                </h2>
                {!readOnly && rules && (
                    <p className="text-muted-foreground text-helper">PDF only, up to {Math.round(rules.max_size_kb / 1024)} MB each.</p>
                )}
            </div>

            <div className="@container min-w-0">
                <div className="grid gap-3 @min-[48rem]:grid-cols-3">
                    {groups.map((group) => (
                        <DocumentCell
                            key={group.purpose}
                            caseId={caseId}
                            group={group}
                            rules={rules}
                            readOnly={readOnly || group.read_only === true}
                            reloadProps={reloadProps}
                            onBusyChange={(busy) => setBusyCells((n) => Math.max(0, n + (busy ? 1 : -1)))}
                        />
                    ))}
                </div>
            </div>
        </section>
    );
}

interface DocumentCellProps {
    caseId: string;
    group: DocumentGroup;
    rules?: UploadRules;
    readOnly: boolean;
    reloadProps: string[];
    onBusyChange: (busy: boolean) => void;
}

/**
 * One category. Choosing files starts their upload (no Upload button). Each
 * file shows its progress, then "Checking" while the server scans it, then
 * "Ready", or a friendly failure with Retry. Changes are announced politely.
 */
function DocumentCell({ caseId, group, rules, readOnly, reloadProps, onBusyChange }: DocumentCellProps) {
    const uploads = useDocumentUploads({
        caseId,
        purpose: group.purpose,
        maxBytes: (rules?.max_size_kb ?? 25600) * 1024,
        reloadProps,
        onBusyChange,
    });
    const inputRef = useRef<HTMLInputElement>(null);
    // What the next files chosen are for: a new version of a document, and a failed attempt they take over from.
    const choosing = useRef<{ replaces: RequestDocument | null; retryKey: string | null }>({ replaces: null, retryKey: null });
    const [note, setNote] = useState<string | null>(null);
    const titleId = `upload-${group.purpose}-title`;
    const hintId = `upload-${group.purpose}-hint`;
    const canUpload = !readOnly && (group.documents.length === 0 || group.documents.some((doc) => doc.can_replace));
    const { setAnnouncement } = uploads;

    // Announce a file the server has finished checking (Checking to Ready or Not accepted).
    const seen = useRef(new Map(group.documents.map((doc) => [doc.id, doc.status_label])));
    useEffect(() => {
        const changed = group.documents.filter((doc) => seen.current.get(doc.id) === 'Scanning' && doc.status_label !== 'Scanning');
        seen.current = new Map(group.documents.map((doc) => [doc.id, doc.status_label]));
        if (changed.length > 0) {
            setAnnouncement(
                changed
                    .map((doc) => (doc.status_label === 'Ready' ? `${doc.display_name} is ready.` : `${doc.display_name}: ${REJECTED_TEXT}`))
                    .join(' '),
            );
        }
    }, [group.documents, setAnnouncement]);

    const open = (replaces: RequestDocument | null, retryKey: string | null = null) => {
        choosing.current = { replaces, retryKey };
        if (inputRef.current) {
            inputRef.current.multiple = replaces === null; // a new version is one file
            inputRef.current.click();
        }
    };

    const onChosen = (files: File[]) => {
        const { replaces, retryKey } = choosing.current;
        choosing.current = { replaces: null, retryKey: null };
        if (files.length === 0) {
            return;
        }
        if (retryKey) {
            uploads.dismiss(retryKey);
        }
        setNote(uploads.choose(replaces ? files.slice(0, 1) : files, replaces, group.documents));
    };

    const retry = (item: UploadItem) => {
        if (item.state.kind === 'failed' && item.state.resend) {
            uploads.resend(item.key);
        } else {
            open(item.replaces, item.key);
        }
    };

    const nothing = uploads.items.length === 0 && group.documents.length === 0;

    return (
        <article aria-labelledby={titleId} className="bg-subtle grid min-w-0 content-start gap-2 rounded-xl border p-3">
            <header className="flex flex-wrap items-center justify-between gap-2">
                <h3 id={titleId} className="text-label leading-snug font-semibold">
                    {group.label}
                </h3>
                {group.is_required ? <Badge variant="outline">Required</Badge> : <span className="text-muted-foreground text-helper">Optional</span>}
            </header>
            {/* The category's example list stays available to screen readers; the cell shows only the label and the control. */}
            {group.hint && (
                <p id={hintId} className="sr-only">
                    {group.hint}
                </p>
            )}

            {canUpload && (
                <div>
                    {/* The hidden input is the real file control; the button opens it. */}
                    <input
                        ref={inputRef}
                        id={`upload-${group.purpose}`}
                        type="file"
                        multiple
                        accept="application/pdf,.pdf"
                        className="sr-only"
                        tabIndex={-1}
                        aria-hidden
                        onChange={(e) => {
                            onChosen(Array.from(e.target.files ?? []));
                            e.target.value = ''; // so choosing the same file again fires onChange
                        }}
                    />
                    <Button type="button" variant="outline" size="sm" aria-describedby={group.hint ? hintId : undefined} onClick={() => open(null)}>
                        <Upload aria-hidden />
                        Choose File<span className="sr-only"> for {group.label}</span>
                    </Button>
                </div>
            )}

            {note && <p className="text-muted-foreground text-helper">{note}</p>}

            {nothing ? (
                <p className="text-muted-foreground text-helper">No file yet.</p>
            ) : (
                <ul className="grid gap-2" aria-label={`Files: ${group.label}`}>
                    {uploads.items.map((item) => (
                        <UploadRow key={item.key} item={item} onRetry={() => retry(item)} onDismiss={() => uploads.dismiss(item.key)} />
                    ))}
                    {group.documents.map((doc) => (
                        <DocumentRow key={doc.id} caseId={caseId} doc={doc} readOnly={readOnly} onReplace={() => open(doc)} />
                    ))}
                </ul>
            )}

            <p aria-live="polite" className="sr-only">
                {uploads.announcement}
            </p>
        </article>
    );
}

/** A file name on one line, cut with an ellipsis; the whole name in the tooltip (and always to screen readers). */
function FileName({ name, meta }: { name: string; meta?: string }) {
    return (
        <div className="grid min-w-0 gap-0.5">
            <p className="flex min-w-0 items-center gap-2 text-sm font-medium">
                <FileText aria-hidden="true" className="text-muted-foreground size-4 shrink-0" />
                <span className="min-w-0 truncate" title={name}>
                    {name}
                </span>
            </p>
            {meta && <p className="text-muted-foreground text-helper truncate pl-6">{meta}</p>}
        </div>
    );
}

/** A friendly failure line (icon and text; never only colour). */
function Failure({ children }: { children: string }) {
    return (
        <p className="text-destructive-text text-helper flex items-start gap-1.5">
            <CircleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <span className="min-w-0 break-words">{children}</span>
        </p>
    );
}

function UploadRow({ item, onRetry, onDismiss }: { item: UploadItem; onRetry: () => void; onDismiss: () => void }) {
    const { state } = item;

    return (
        <li className="bg-card grid gap-1.5 rounded-lg border px-3 py-2">
            <FileName name={item.file.name} meta={item.replaces ? `Replaces ${item.replaces.display_name}` : undefined} />
            {state.kind === 'waiting' && <p className="text-muted-foreground text-helper">Waiting to upload</p>}
            {state.kind === 'uploading' && <UploadProgress name={item.file.name} percent={state.percent} />}
            {state.kind === 'failed' && (
                <>
                    <Failure>{state.message}</Failure>
                    <div className="flex flex-wrap items-center gap-1">
                        <Button type="button" variant="outline" size="sm" onClick={onRetry}>
                            <RotateCw aria-hidden />
                            Retry<span className="sr-only"> {item.file.name}</span>
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={onDismiss}>
                            <X aria-hidden />
                            Dismiss<span className="sr-only"> {item.file.name}</span>
                        </Button>
                    </div>
                </>
            )}
        </li>
    );
}

function ScanState({ status }: { status: RequestDocument['status_label'] }) {
    const text = STATUS_TEXT[status];

    if (status === 'Ready') {
        return (
            <span className="text-status-success-foreground text-helper inline-flex items-center gap-1.5 font-medium">
                <CircleCheck aria-hidden="true" className="size-4 shrink-0" />
                {text}
            </span>
        );
    }
    if (status === 'Rejected') {
        return (
            <span className="text-destructive-text text-helper inline-flex items-center gap-1.5 font-medium">
                <CircleAlert aria-hidden="true" className="size-4 shrink-0" />
                {text}
            </span>
        );
    }

    return (
        <span className="text-muted-foreground text-helper inline-flex items-center gap-1.5 font-medium">
            <LoaderCircle aria-hidden="true" className="size-4 shrink-0 motion-safe:animate-spin" />
            {text}
        </span>
    );
}

function DocumentRow({ caseId, doc, readOnly, onReplace }: { caseId: string; doc: RequestDocument; readOnly: boolean; onReplace: () => void }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [confirming, setConfirming] = useState(false);
    const [reason, setReason] = useState('');
    const reasonId = `withdraw-${doc.id}`;
    const rejected = doc.status_label === 'Rejected';

    // The reason is optional (slice F1): the server accepts a removal without one.
    const withdraw = (e: FormEvent) => {
        e.preventDefault();
        router.post(
            route('requests.documents.withdraw', [caseId, doc.id]),
            { reason: reason.trim() === '' ? null : reason },
            { preserveScroll: true, onSuccess: () => setConfirming(false) },
        );
    };

    return (
        <li className="bg-card grid gap-1.5 rounded-lg border px-3 py-2">
            <FileName name={doc.display_name} meta={`Version ${doc.version} · ${formatSize(doc.size_bytes)}`} />
            <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                <ScanState status={doc.status_label} />
                <div className="flex flex-wrap items-center gap-1">
                    {doc.can_download && (
                        <a
                            href={route('documents.download', doc.id)}
                            className="text-primary focus-visible:ring-ring inline-flex min-h-8 items-center rounded-md px-2 text-sm font-medium underline focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            Download<span className="sr-only"> {doc.display_name}</span>
                        </a>
                    )}
                    {!readOnly && doc.can_replace && !rejected && (
                        <Button type="button" variant="ghost" size="sm" onClick={onReplace}>
                            Replace<span className="sr-only"> {doc.display_name}</span>
                        </Button>
                    )}
                    {!readOnly && doc.can_withdraw && !confirming && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => setConfirming(true)}>
                            Remove<span className="sr-only"> {doc.display_name}</span>
                        </Button>
                    )}
                </div>
            </div>

            {rejected && (
                <>
                    <Failure>{REJECTED_TEXT}</Failure>
                    {!readOnly && doc.can_replace && (
                        <div>
                            <Button type="button" variant="outline" size="sm" onClick={onReplace}>
                                <RotateCw aria-hidden />
                                Retry<span className="sr-only">: choose a file to replace {doc.display_name}</span>
                            </Button>
                        </div>
                    )}
                </>
            )}

            {confirming && (
                <form onSubmit={withdraw} className="mt-1 grid gap-2">
                    <Label htmlFor={reasonId}>Why are you removing this file?</Label>
                    <textarea
                        id={reasonId}
                        maxLength={500}
                        rows={2}
                        className="border-input bg-input-bg focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    <InputError message={errors.reason ?? errors.document} />
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" variant="destructive" size="sm">
                            Remove File
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
        </li>
    );
}
