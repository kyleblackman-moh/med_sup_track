import { Button } from '@/components/ui/button';
import { type Readiness } from '@/types/requests';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    caseId: string;
    readiness: Readiness;
    canSubmit: boolean;
}

/**
 * Submit a draft. The server decides (can.submit from the readiness check); this
 * only shows its answer and asks for a second, inline confirmation.
 */
export function SubmitPanel({ caseId, readiness, canSubmit }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const submitErrors = Object.entries(errors).filter(([key]) => key.startsWith('submit.'));

    const submit = () => {
        setProcessing(true);
        router.post(route('requests.submit', caseId), {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    return (
        <section aria-labelledby="submit-heading" className="rounded-lg border p-4">
            <h2 id="submit-heading" className="text-lg font-semibold">
                Submit request
            </h2>

            <div aria-live="polite">
                {submitErrors.length > 0 && (
                    <div role="alert" className="mt-2 text-sm text-red-700 dark:text-red-400">
                        <p className="font-medium">The request was not submitted:</p>
                        <ul className="mt-1 list-disc pl-5">
                            {submitErrors.map(([key, message]) => (
                                <li key={key}>{message}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            {!canSubmit ? (
                <div className="mt-2 text-sm">
                    <p>This request cannot be submitted yet.</p>
                    {readiness.errors.length > 0 && (
                        <ul className="mt-1 list-disc pl-5 text-red-700 dark:text-red-400">
                            {readiness.errors.map((item) => (
                                <li key={`${item.key}-${item.message}`}>{item.message}</li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : !confirming ? (
                <div className="mt-2 space-y-2">
                    <p className="text-muted-foreground text-sm">Everything needed is in place.</p>
                    <Button type="button" onClick={() => setConfirming(true)}>
                        Submit request
                    </Button>
                </div>
            ) : (
                <div className="mt-2 space-y-3" role="group" aria-labelledby="submit-confirm-text">
                    <p id="submit-confirm-text" className="text-sm font-medium">
                        After you submit you cannot edit this request. You must also deliver the hardcopy to the CMO's Office quoting the reference
                        number.
                    </p>
                    <div className="flex gap-2">
                        <Button type="button" onClick={submit} disabled={processing}>
                            {processing ? 'Submitting…' : 'Confirm and submit'}
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => setConfirming(false)} disabled={processing}>
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}
