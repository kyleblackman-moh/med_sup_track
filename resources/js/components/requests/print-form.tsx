import { RequestSummary } from '@/components/requests/request-summary';
import { type RequestSummaryView } from '@/types/summary';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

/** App\Http\Resources\Requests\PrintForm: the header, then the one RequestSummary. Display text only. */
export interface PrintFormView extends RequestSummaryView {
    header: {
        /** "—" for a draft (no reference yet). */
        reference: string;
        type: string;
        /** The vendor label for the vendor, the staff label for staff. */
        status: string;
        organisation: string;
        /** Already in Port of Spain time ("23 Nov 2026, 10:30 pm"). */
        printed_at: string;
        printed_at_iso: string;
    };
}

/**
 * The printable request form (Blueprint print invoice receipt), shared by the
 * vendor (requests/print) and staff (cases/print) pages. A standalone page
 * without the app drawer, so only the form prints: a screen-only toolbar, the
 * document header with the reference stamp (as the submission receipt), then
 * every section of the server's RequestSummary in order, with every value and
 * computed figure as the server sends them. Print: black on white, no
 * toolbar, sections and table rows kept whole.
 */
export function PrintForm({ print, backHref, backLabel }: { print: PrintFormView; backHref: string; backLabel: string }) {
    const { header } = print;
    const hasReference = header.reference !== '—';

    return (
        <div className="bg-base-200 text-base-content print:bg-base-100 min-h-screen">
            <Head title={hasReference ? `Print ${header.reference}` : 'Print Request'} />

            <div className="space-y-4 px-4 py-4 lg:px-6 print:p-0">
                <nav aria-label="Print form" className="flex flex-wrap items-center justify-between gap-2 print:hidden">
                    <Link href={backHref} className="btn btn-ghost btn-sm capitalize">
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        {backLabel}
                    </Link>
                    <button type="button" className="btn btn-primary btn-sm capitalize" onClick={() => window.print()}>
                        <Printer className="size-4" aria-hidden="true" />
                        Print / Save as PDF
                    </button>
                </nav>

                <article className="card bg-base-100 border-base-300 min-w-0 border print:border-0">
                    <div className="card-body min-w-0 gap-6 p-4 md:p-6 print:p-0">
                        <header className="border-base-300 flex flex-wrap items-end justify-between gap-4 border-b pb-4 print:break-inside-avoid">
                            <div className="min-w-0 space-y-1">
                                <p className="text-base-content/70 text-sm">Ministry of Health · Non-objection request</p>
                                <h1 className="text-2xl font-semibold capitalize">Request Form</h1>
                                <p className="font-medium">{header.type}</p>
                            </div>

                            <section aria-label="Reference Number" className="border-base-content rounded-box border-2 px-5 py-3 text-center">
                                <p className="text-sm capitalize">Reference Number</p>
                                <p className="font-mono text-3xl font-bold tracking-wide">{header.reference}</p>
                            </section>
                        </header>

                        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4 print:grid-cols-4">
                            <div className="min-w-0">
                                <dt className="text-base-content/70 text-sm capitalize">Organisation</dt>
                                <dd className="break-words">{header.organisation || '—'}</dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-base-content/70 text-sm capitalize">Request Type</dt>
                                <dd className="break-words">{header.type}</dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-base-content/70 text-sm capitalize">Status</dt>
                                <dd>
                                    <span className="badge badge-sm badge-outline capitalize">{header.status}</span>
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-base-content/70 text-sm capitalize">Printed</dt>
                                <dd className="tabular-nums">
                                    <time dateTime={header.printed_at_iso}>{header.printed_at}</time>
                                </dd>
                            </div>
                        </dl>

                        {/* The one RequestSummary, as on the request and case pages. In print: table rows never split. */}
                        <div className="min-w-0 print:[&_tr]:break-inside-avoid">
                            <RequestSummary summary={print} idPrefix="print" />
                        </div>
                    </div>
                </article>
            </div>
        </div>
    );
}
