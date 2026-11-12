import { type Readiness } from '@/types/requests';

/** Submission readiness from the server, as of the last save. */
export function ReadinessPanel({ readiness }: { readiness: Readiness }) {
    return (
        <section aria-labelledby="readiness-heading" className="rounded-lg border p-4">
            <h2 id="readiness-heading" className="text-base font-semibold">
                Ready to submit?
            </h2>
            <p className="text-muted-foreground mt-1 text-sm">
                {readiness.is_ready
                    ? 'Everything needed so far is in place.'
                    : `${readiness.errors.length} item${readiness.errors.length === 1 ? '' : 's'} still needed.`}{' '}
                Based on your last save. Document checks happen at submission.
            </p>

            {readiness.errors.length > 0 && (
                <>
                    <h3 className="mt-3 text-sm font-medium">Still needed</h3>
                    <ul className="mt-1 list-disc space-y-1 pl-5 text-sm text-red-700 dark:text-red-400">
                        {readiness.errors.map((item) => (
                            <li key={`${item.key}-${item.message}`}>{item.message}</li>
                        ))}
                    </ul>
                </>
            )}

            {readiness.warnings.length > 0 && (
                <>
                    <h3 className="mt-3 text-sm font-medium">Worth checking</h3>
                    <ul className="mt-1 list-disc space-y-1 pl-5 text-sm text-amber-700 dark:text-amber-400">
                        {readiness.warnings.map((item) => (
                            <li key={`${item.key}-${item.message}`}>{item.message}</li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}
