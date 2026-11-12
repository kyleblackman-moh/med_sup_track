import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { type DocumentGroup, type RequestDocument, type UploadRules } from '@/types/requests';
import { router, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

interface Props {
    caseId: string;
    groups: DocumentGroup[];
    rules?: UploadRules;
    /** Read-only lists (Show page): no upload, replace or remove. */
    readOnly?: boolean;
}

const STATUS_VARIANT: Record<RequestDocument['status_label'], 'secondary' | 'default' | 'destructive'> = {
    Scanning: 'secondary',
    Ready: 'default',
    Rejected: 'destructive',
};

function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Supporting documents, one card per document type. The server decides what is
 * listed and allowed (can_* flags) and validates every upload; this only sends
 * requests through Inertia. While anything is being scanned, the documents and
 * readiness props are reloaded every 5 seconds.
 */
export function SupportingDocuments({ caseId, groups, rules, readOnly = false }: Props) {
    const scanning = groups.some((group) => group.documents.some((doc) => doc.status_label === 'Scanning'));

    useEffect(() => {
        if (!scanning) {
            return;
        }
        const timer = window.setInterval(() => router.reload({ only: ['documents', 'readiness'] }), 5000);

        return () => window.clearInterval(timer);
    }, [scanning]);

    return (
        <section aria-labelledby="documents-heading" className="space-y-4">
            <div>
                <h2 id="documents-heading" className="text-lg font-semibold">
                    Supporting documents
                </h2>
                {!readOnly && rules && (
                    <p className="text-muted-foreground text-sm">
                        Accepted: {rules.extensions.map((e) => e.toUpperCase()).join(', ')}, up to {Math.round(rules.max_size_kb / 1024)} MB each.
                        Every file is checked for viruses before it can be used.
                    </p>
                )}
            </div>

            {groups.map((group) => (
                <DocumentCard key={group.purpose} caseId={caseId} group={group} readOnly={readOnly} />
            ))}
        </section>
    );
}

function DocumentCard({ caseId, group, readOnly }: { caseId: string; group: DocumentGroup; readOnly: boolean }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [file, setFile] = useState<File | null>(null);
    const [replacing, setReplacing] = useState<RequestDocument | null>(null);
    const [progress, setProgress] = useState<number | null>(null);
    const [active, setActive] = useState(false);
    const inputId = `upload-${group.purpose}`;
    const canUpload = !readOnly && (group.documents.length === 0 || group.documents.some((d) => d.can_replace));

    const upload = (e: FormEvent) => {
        e.preventDefault();
        if (!file) {
            return;
        }
        setActive(true);
        router.post(
            route('requests.documents.store', caseId),
            { purpose: group.purpose, file, supersedes_id: replacing?.id ?? null },
            {
                forceFormData: true,
                preserveScroll: true,
                onProgress: (event) => setProgress(event?.percentage ?? null),
                onSuccess: () => {
                    setFile(null);
                    setReplacing(null);
                },
                onFinish: () => setProgress(null),
            },
        );
    };

    return (
        <article className="rounded-lg border p-4" aria-labelledby={`${inputId}-title`}>
            <header className="flex flex-wrap items-center gap-2">
                <h3 id={`${inputId}-title`} className="font-medium">
                    {group.label}
                </h3>
                {group.is_required ? <Badge variant="outline">Required</Badge> : <span className="text-muted-foreground text-xs">Optional</span>}
            </header>

            {group.documents.length === 0 ? (
                <p className="text-muted-foreground mt-2 text-sm">No file uploaded.</p>
            ) : (
                <ul className="mt-3 space-y-3">
                    {group.documents.map((doc) => (
                        <DocumentRow
                            key={doc.id}
                            caseId={caseId}
                            doc={doc}
                            readOnly={readOnly}
                            onReplace={() => {
                                setReplacing(doc);
                                document.getElementById(inputId)?.focus();
                            }}
                        />
                    ))}
                </ul>
            )}

            {canUpload && (
                <form onSubmit={upload} className="mt-4 flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor={inputId}>{replacing ? `Replace ${replacing.display_name}` : 'Add a file'}</Label>
                        <input
                            id={inputId}
                            type="file"
                            className="text-sm"
                            aria-describedby={active && errors.file ? `${inputId}-error` : undefined}
                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                        />
                    </div>
                    <Button type="submit" size="sm" disabled={!file || progress !== null}>
                        {replacing ? 'Upload replacement' : 'Upload'}
                    </Button>
                    {replacing && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => setReplacing(null)}>
                            Cancel replace
                        </Button>
                    )}
                    {progress !== null && (
                        <progress value={progress} max={100} aria-label={`Uploading ${group.label}`} className="h-2 w-40">
                            {progress}%
                        </progress>
                    )}
                    {active && (
                        <div aria-live="polite" className="w-full">
                            <InputError id={`${inputId}-error`} message={errors.file ?? errors.purpose ?? errors.supersedes_id} />
                        </div>
                    )}
                </form>
            )}
        </article>
    );
}

function DocumentRow({ caseId, doc, readOnly, onReplace }: { caseId: string; doc: RequestDocument; readOnly: boolean; onReplace: () => void }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [confirming, setConfirming] = useState(false);
    const [reason, setReason] = useState('');
    const reasonId = `withdraw-${doc.id}`;

    const withdraw = (e: FormEvent) => {
        e.preventDefault();
        router.post(
            route('requests.documents.withdraw', [caseId, doc.id]),
            { reason },
            { preserveScroll: true, onSuccess: () => setConfirming(false) },
        );
    };

    return (
        <li className="rounded-md border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-medium break-all">{doc.display_name}</p>
                    <p className="text-muted-foreground text-xs">
                        Version {doc.version} · {formatSize(doc.size_bytes)}
                        {doc.uploaded_at && ` · ${new Date(doc.uploaded_at).toLocaleString('en-TT', { timeZone: 'America/Port_of_Spain' })}`}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={STATUS_VARIANT[doc.status_label]}>{doc.status_label}</Badge>
                    {doc.can_download && (
                        <a href={route('documents.download', doc.id)} className="text-sm underline">
                            Download<span className="sr-only"> {doc.display_name}</span>
                        </a>
                    )}
                    {!readOnly && doc.can_replace && (
                        <Button type="button" variant="outline" size="sm" onClick={onReplace}>
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

            {doc.status_label === 'Rejected' && (
                <p className="mt-2 text-sm text-red-700 dark:text-red-400">This file failed the security check. Upload a replacement.</p>
            )}

            {confirming && (
                <form onSubmit={withdraw} className="mt-3 grid gap-2">
                    <Label htmlFor={reasonId}>Why are you removing this file?</Label>
                    <textarea
                        id={reasonId}
                        required
                        maxLength={500}
                        rows={2}
                        className="border-input rounded-md border bg-transparent px-3 py-2 text-sm"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    <InputError message={errors.reason ?? errors.document} />
                    <div className="flex gap-2">
                        <Button type="submit" variant="destructive" size="sm" disabled={reason.trim() === ''}>
                            Remove file
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
