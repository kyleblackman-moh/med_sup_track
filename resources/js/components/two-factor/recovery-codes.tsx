import { Check, Copy, Download } from 'lucide-react';
import { useState } from 'react';

/** MFA: the recovery codes as a numbered ledger, in the order Fortify issued them. Shown once, until the user says they are saved. */
export function RecoveryCodeList({ codes }: { codes: string[] }) {
    return (
        <ol aria-label="Recovery codes" className="bg-base-200 border-base-300 rounded-box grid gap-x-6 gap-y-1 border p-3 sm:grid-cols-2">
            {codes.map((code, i) => (
                <li key={code} className="flex items-baseline gap-3 text-sm">
                    <span className="text-base-content/60 text-helper w-5 text-right tabular-nums" aria-hidden="true">
                        {String(i + 1).padStart(2, '0')}
                    </span>
                    <span className="font-mono tracking-wide">{code}</span>
                </li>
            ))}
        </ol>
    );
}

/**
 * Copy (to the clipboard) and Download (the server's .txt, security.mfa.recovery-codes.download,
 * which works only while the codes wait to be acknowledged). "Copied" is announced politely.
 */
export function RecoveryCodeActions({ codes }: { codes: string[] }) {
    const [copied, setCopied] = useState(false);
    const [copyFailed, setCopyFailed] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(codes.join('\n'));
            setCopied(true);
            setCopyFailed(false);
            window.setTimeout(() => setCopied(false), 2500);
        } catch {
            setCopyFailed(true);
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <button type="button" className="btn btn-sm capitalize" onClick={() => void copy()}>
                <Copy className="size-4" aria-hidden="true" />
                Copy Codes
            </button>
            {/* A plain link (not an Inertia visit): the browser saves the file. */}
            <a href={route('security.mfa.recovery-codes.download')} className="btn btn-sm capitalize" download>
                <Download className="size-4" aria-hidden="true" />
                Download .txt
            </a>
            <span role="status" aria-live="polite" className="text-helper text-base-content/70">
                {copied && (
                    <span className="text-success inline-flex items-center gap-1">
                        <Check className="size-4" aria-hidden="true" />
                        Copied
                    </span>
                )}
                {copyFailed && 'Copy is blocked in this browser. Use Download instead.'}
            </span>
        </div>
    );
}
