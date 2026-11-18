import InputError from '@/components/input-error';
import { formatSize, UploadDropZone, type UploadDropZoneHandle } from '@/components/requests/upload-drop-zone';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { type DocumentGroup, type RequestDocument, type UploadRules } from '@/types/requests';
import { router, usePage } from '@inertiajs/react';
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

const STATUS_VARIANT: Record<RequestDocument['status_label'], 'secondary' | 'default' | 'destructive'> = {
    Scanning: 'secondary',
    Ready: 'default',
    Rejected: 'destructive',
};

/**
 * Supporting documents, one card per document type, each with its own drop
 * zone (UI-3). The server decides what is listed and allowed (can_* flags) and
 * validates every upload; this only sends requests through Inertia. While
 * anything is being scanned, the documents props are reloaded every 5 seconds,
 * except while files are being sent.
 */
export function SupportingDocuments({ caseId, groups, rules, readOnly = false, reloadProps = ['documents', 'readiness'], embedded = false }: Props) {
    const scanning = groups.some((group) => group.documents.some((doc) => doc.status_label === 'Scanning'));
    const reloadKey = reloadProps.join(','); // a stable dependency (the default array is new on every render)
    const [busyZones, setBusyZones] = useState(0);
    const uploading = busyZones > 0;

    useEffect(() => {
        if (!scanning || uploading) {
            return;
        }
        const timer = window.setInterval(() => router.reload({ only: reloadKey.split(',') }), 5000);

        return () => window.clearInterval(timer);
    }, [scanning, uploading, reloadKey]);

    return (
        <section aria-labelledby="documents-heading" className="space-y-4">
            <div>
                <h2 id="documents-heading" className={embedded ? 'sr-only' : 'text-lg font-semibold'}>
                    Supporting documents
                </h2>
                {!readOnly && rules && (
                    <p className="text-muted-foreground text-sm">
                        PDF only, up to {Math.round(rules.max_size_kb / 1024)} MB each. Every file is checked for viruses before it can be used.
                    </p>
                )}
            </div>

            {groups.map((group) => (
                <DocumentCard
                    key={group.purpose}
                    caseId={caseId}
                    group={group}
                    rules={rules}
                    readOnly={readOnly || group.read_only === true}
                    reloadProps={reloadProps}
                    onBusyChange={(busy) => setBusyZones((n) => Math.max(0, n + (busy ? 1 : -1)))}
                />
            ))}
        </section>
    );
}

interface DocumentCardProps {
    caseId: string;
    group: DocumentGroup;
    rules?: UploadRules;
    readOnly: boolean;
    reloadProps: string[];
    onBusyChange: (busy: boolean) => void;
}

function DocumentCard({ caseId, group, rules, readOnly, reloadProps, onBusyChange }: DocumentCardProps) {
    const zone = useRef<UploadDropZoneHandle>(null);
    const titleId = `upload-${group.purpose}-title`;
    const canUpload = !readOnly && (group.documents.length === 0 || group.documents.some((d) => d.can_replace));

    return (
        <article className="rounded-lg border p-4" aria-labelledby={titleId}>
            <header className="flex flex-wrap items-center gap-2">
                <h3 id={titleId} className="font-medium">
                    {group.label}
                </h3>
                {group.is_required ? <Badge variant="outline">Required</Badge> : <span className="text-muted-foreground text-[13px]">Optional</span>}
            </header>
            {group.hint && <p className="text-muted-foreground mt-1 text-sm">{group.hint}</p>}

            {canUpload && (
                <UploadDropZone ref={zone} caseId={caseId} group={group} rules={rules} reloadProps={reloadProps} onBusyChange={onBusyChange} />
            )}

            {group.documents.length === 0 ? (
                <p className="text-muted-foreground mt-3 text-sm">No file uploaded.</p>
            ) : (
                <ul className="mt-3 space-y-3" aria-label={`Uploaded files: ${group.label}`}>
                    {group.documents.map((doc) => (
                        <DocumentRow key={doc.id} caseId={caseId} doc={doc} readOnly={readOnly} onReplace={() => zone.current?.replace(doc)} />
                    ))}
                </ul>
            )}
        </article>
    );
}

function DocumentRow({ caseId, doc, readOnly, onReplace }: { caseId: string; doc: RequestDocument; readOnly: boolean; onReplace: () => void }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [confirming, setConfirming] = useState(false);
    const [reason, setReason] = useState('');
    const reasonId = `withdraw-${doc.id}`;

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
        <li className="rounded-md border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium break-all">{doc.display_name}</p>
                    <p className="text-muted-foreground text-[13px]">
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
                <p className="text-destructive mt-2 text-sm">This file failed the security check. Upload a replacement.</p>
            )}

            {confirming && (
                <form onSubmit={withdraw} className="mt-3 grid gap-2">
                    <Label htmlFor={reasonId}>Why are you removing this file?</Label>
                    <textarea
                        id={reasonId}
                        maxLength={500}
                        rows={2}
                        className="border-input rounded-md border bg-transparent px-3 py-2 text-sm"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    <InputError message={errors.reason ?? errors.document} />
                    <div className="flex gap-2">
                        <Button type="submit" variant="destructive" size="sm">
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
