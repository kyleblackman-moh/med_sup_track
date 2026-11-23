import InputError from '@/components/input-error';
import { useForm } from '@inertiajs/react';
import { PackageSearch, Search } from 'lucide-react';
import { type FormEvent } from 'react';

/**
 * HARDCOPY-1: open a case from the reference number on a paper file that has
 * just arrived (typed, or read by a scanner, which types the reference and
 * presses Enter). The server finds an exact match among the cases this user
 * may see and opens it, where the hardcopy is recorded; anything else is one
 * "No case with that reference".
 */
export function ReferenceLookup() {
    const form = useForm({ reference: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (form.data.reference.trim() === '') return;
        form.get(route('cases.hardcopy-lookup'), { preserveScroll: true, preserveState: true });
    };

    return (
        <section aria-labelledby="reference-lookup-heading" className="card bg-base-100 border-base-300 border">
            <form onSubmit={submit} className="card-body gap-2 p-4" noValidate>
                <h2 id="reference-lookup-heading" className="card-title text-base capitalize">
                    <PackageSearch className="text-primary size-4 shrink-0" aria-hidden="true" />
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
                    Type the full reference from the paper file. The case opens, ready to record the hardcopy.
                </p>
                <InputError id="reference-lookup-error" message={form.errors.reference} />
            </form>
        </section>
    );
}
