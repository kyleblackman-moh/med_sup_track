/**
 * FORM-COMPACT-1 (3): the browser's upload progress for one file, as Inertia
 * reports it: a daisyUI progress bar (role="progressbar") and the same number
 * as text. The only place React shows a percentage that the server did not
 * format, because it is the transfer's progress, not data.
 */
export function UploadProgress({ name, percent }: { name: string; percent: number }) {
    return (
        <div className="flex items-center gap-2">
            <progress
                role="progressbar"
                aria-label={`Uploading ${name}`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                className="progress h-1.5 min-w-0 flex-1"
                value={percent}
                max={100}
            />
            <span className="text-base-content/70 text-helper w-10 shrink-0 text-right tabular-nums">{percent}%</span>
        </div>
    );
}
