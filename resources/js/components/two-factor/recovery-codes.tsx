import { APP_BRAND } from '@/components/app-logo';
import { Check, Copy, Download } from 'lucide-react';
import { useState } from 'react';

/** The plain-text file a user keeps: a heading line, then one code per line. */
function recoveryCodesText(codes: string[]): string {
    return [`${APP_BRAND.name} recovery codes`, 'Each code works once. Keep this file somewhere safe.', '', ...codes, ''].join('\n');
}

/** MFA: the recovery codes as a numbered ledger, in the order Fortify issued them. Shown once, straight after they are made. */
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
 * Copy and Download (.txt, made in the browser from a Blob: the codes never go
 * back to the server) for a footer row. "Copied" is announced politely.
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

    const download = () => {
        const url = URL.createObjectURL(new Blob([recoveryCodesText(codes)], { type: 'text/plain' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'recovery-codes.txt';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    };

    return (
        <>
            <span role="status" aria-live="polite" className="text-helper text-base-content/70 me-auto">
                {copied && (
                    <span className="text-success inline-flex items-center gap-1">
                        <Check className="size-4" aria-hidden="true" />
                        Copied
                    </span>
                )}
                {copyFailed && 'Copy is blocked in this browser. Use Download instead.'}
            </span>
            <button type="button" className="btn btn-sm capitalize" onClick={() => void copy()}>
                <Copy className="size-4" aria-hidden="true" />
                Copy Codes
            </button>
            <button type="button" className="btn btn-sm capitalize" onClick={download}>
                <Download className="size-4" aria-hidden="true" />
                Download .txt
            </button>
        </>
    );
}
