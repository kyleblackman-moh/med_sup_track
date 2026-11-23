import InputError from '@/components/input-error';
import EnrolmentLayout from '@/components/two-factor/enrolment-layout';
import { RecoveryCodeActions, RecoveryCodeList } from '@/components/two-factor/recovery-codes';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, TriangleAlert } from 'lucide-react';
import { FormEventHandler } from 'react';

/**
 * MFA-1: the recovery codes, after enrolment (the last setup step) or a regenerate,
 * shown only until the user ticks "I have saved these codes". Copy and the
 * server's .txt download sit with the list; Continue (the acknowledge POST)
 * ends the card and stays disabled until the box is ticked. The server holds
 * the user on this page until then.
 */
export default function MfaRecoveryCodes({ codes }: { codes: string[] }) {
    const { data, setData, post, processing, errors } = useForm<{ saved: boolean }>({ saved: false });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('security.mfa.recovery-codes.acknowledge'));
    };

    return (
        <EnrolmentLayout
            title="Save Your Recovery Codes"
            description={`If you lose your phone, each of these ${codes.length} codes signs you in once instead of the 6-digit code.`}
        >
            <Head title="Recovery Codes" />

            <div role="alert" className="alert alert-warning alert-soft items-start text-sm">
                <TriangleAlert className="size-4 shrink-0" aria-hidden="true" />
                <span>They are shown only now. Keep them somewhere safe and private, away from your phone.</span>
            </div>

            <RecoveryCodeList codes={codes} />
            <RecoveryCodeActions codes={codes} />

            <form onSubmit={submit} noValidate>
                <fieldset className="fieldset py-0" disabled={processing}>
                    <label className="label text-base-content cursor-pointer items-start gap-2 text-sm whitespace-normal">
                        <input
                            type="checkbox"
                            name="saved"
                            className="checkbox checkbox-sm mt-0.5"
                            checked={data.saved}
                            onChange={(e) => setData('saved', e.target.checked)}
                            aria-invalid={errors.saved ? true : undefined}
                            aria-describedby={errors.saved ? 'saved-error' : undefined}
                        />
                        I have saved these codes
                    </label>
                    <InputError id="saved-error" message={errors.saved} />
                </fieldset>

                <div className="border-base-300 mt-6 flex items-center justify-end gap-2 border-t pt-4">
                    <button
                        type="submit"
                        className="btn btn-primary btn-sm capitalize"
                        disabled={processing || !data.saved}
                        aria-busy={processing || undefined}
                    >
                        {processing && <span className="loading loading-spinner loading-xs" aria-hidden="true" />}
                        Continue
                        {!processing && <ArrowRight className="size-4" aria-hidden="true" />}
                    </button>
                </div>
            </form>
        </EnrolmentLayout>
    );
}
