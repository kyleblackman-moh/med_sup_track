import InputError from '@/components/input-error';
import { useForm } from '@inertiajs/react';
import { ScanLine, Search } from 'lucide-react';
import { type FormEvent } from 'react';

/**
 * FR-04: open a case from its reference number, typed or read by a scanner
 * from the hardcopy cover sheet's QR code (a scanner types the reference and
 * presses Enter). The server finds it among the cases this user may see and
 * opens it, where the hardcopy is recorded; anything else is one "not found".
 */
export function ReferenceLookup() {
    const form = useForm({ reference: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (form.data.reference.trim() === '') return;
        form.get(route('cases.lookup'), { preserveScroll: true, preserveState: true });
    };

    return (
        <section aria-labelledby="reference-lookup-heading" className="card bg-base-100 border-base-300 border">
            <form onSubmit={submit} className="card-body gap-2 p-4" noValidate>
                <h2 id="reference-lookup-heading" className="card-title text-base capitalize">
                    <ScanLine className="text-primary size-4 shrink-0" aria-hidden="true" />
                    Hardcopy Arrived?
                </h2>
                <label className="label text-base-content text-sm font-medium capitalize" htmlFor="reference-lookup">
                    Reference Number
                </label>
                <div className="flex flex-wrap items-start gap-2">
                    <input
                        id="reference-lookup"
                        type="text"
                        inputMode="text"
                        autoComplete="off"
                        spellCheck={false}
                        className="input input-sm w-full font-mono uppercase sm:w-72"
                        value={form.data.reference}
                        onChange={(e) => form.setData('reference', e.target.value)}
                        aria-invalid={form.errors.reference ? true : undefined}
                        aria-describedby="reference-lookup-hint reference-lookup-error"
                    />
                    <button type="submit" className="btn btn-sm capitalize" disabled={form.processing || form.data.reference.trim() === ''}>
                        <Search className="size-4" aria-hidden="true" />
                        Open Case
                    </button>
                </div>
                <p id="reference-lookup-hint" className="text-base-content/70 text-helper">
                    Type the reference or scan the QR code on the cover sheet. The case opens, ready to record the hardcopy.
                </p>
                <InputError id="reference-lookup-error" message={form.errors.reference} />
            </form>
        </section>
    );
}
