import { type Readiness } from '@/types/requests';
import { router, usePage } from '@inertiajs/react';
import { CircleAlert, Send } from 'lucide-react';
import { useState } from 'react';

interface Props {
    caseId: string;
    readiness: Readiness;
    canSubmit: boolean;
}

/**
 * Submit a draft. The server decides (can.submit from the readiness check); this
 * only shows its answer and asks for a second, inline confirmation. The
 * buttons sit in the panel's footer row (secondary first, primary last).
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
        <section aria-labelledby="submit-heading" className="card bg-base-100 border-base-300 min-w-0 border">
            <div className="card-body min-w-0 gap-3 p-4 md:p-5">
                <h2 id="submit-heading" className="card-title text-lg capitalize">
                    Submit Request
                </h2>

                <div aria-live="polite" className="empty:hidden">
                    {submitErrors.length > 0 && (
                        <div role="alert" className="alert alert-error alert-soft items-start text-sm">
                            <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <div>
                                <p className="font-medium">The request was not submitted:</p>
                                <ul className="mt-1 [list-style-type:disc] pl-5">
                                    {submitErrors.map(([key, message]) => (
                                        <li key={key}>{message}</li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                </div>

                {!canSubmit ? (
                    <div className="text-sm">
                        <p>This request cannot be submitted yet.</p>
                        {readiness.errors.length > 0 && (
                            <ul className="text-error mt-1 [list-style-type:disc] pl-5">
                                {readiness.errors.map((item) => (
                                    <li key={`${item.key}-${item.message}`}>{item.message}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                ) : !confirming ? (
                    <>
                        <p className="text-base-content/70 text-sm">Everything needed is in place.</p>
                        <div className="card-actions justify-end">
                            <button type="button" className="btn btn-primary btn-sm capitalize max-md:h-11" onClick={() => setConfirming(true)}>
                                <Send className="size-4" aria-hidden="true" />
                                Submit Request
                            </button>
                        </div>
                    </>
                ) : (
                    <div className="space-y-3" role="group" aria-labelledby="submit-confirm-text">
                        <p id="submit-confirm-text" className="text-sm font-medium">
                            After you submit you cannot edit this request. You must also deliver the hardcopy to the CMO's Office quoting the
                            reference number.
                        </p>
                        <div className="card-actions justify-end">
                            <button
                                type="button"
                                className="btn btn-ghost btn-sm capitalize max-md:h-11"
                                onClick={() => setConfirming(false)}
                                disabled={processing}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary btn-sm capitalize max-md:h-11"
                                onClick={submit}
                                disabled={processing}
                                aria-busy={processing || undefined}
                            >
                                {processing && <span className="loading loading-spinner loading-xs" aria-hidden="true" />}
                                {processing ? 'Submitting…' : 'Confirm and Submit'}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
