import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Mailbox, Printer, QrCode } from 'lucide-react';
import { type ReactNode } from 'react';

interface ReceiptDocument {
    label: string;
    display_name: string;
    version: number;
    uploaded_at: string | null;
}

interface Receipt {
    id: string;
    reference_no: string;
    organisation_name: string | null;
    procurement_type: string | null;
    item_description: string | null;
    submitted_at: string | null;
    submitted_by: string | null;
    documents: ReceiptDocument[];
    hardcopy_notice: string;
}

function localDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: 'America/Port_of_Spain', dateStyle: 'long', timeStyle: 'short' }) : '—';
}

/** One labelled value of the receipt. */
function Item({ label, children, wide = false }: { label: string; children: ReactNode; wide?: boolean }) {
    return (
        <div className={wide ? 'min-w-0 sm:col-span-2 print:col-span-2' : 'min-w-0'}>
            <dt className="text-base-content/70 text-sm capitalize">{label}</dt>
            <dd className="break-words">{children}</dd>
        </div>
    );
}

/**
 * Printable submission receipt (Blueprint order confirmation). Standalone page,
 * so only the receipt prints: the reference number leads, in a bordered stamp
 * block, then what was submitted, the documents received and the hardcopy
 * instruction. Flat: borders, no shadows, so the print matches the screen.
 */
export default function ReceiptPage({ receipt }: { receipt: Receipt }) {
    return (
        <div className="bg-base-200 text-base-content print:bg-base-100 min-h-screen">
            <Head title={`Receipt ${receipt.reference_no}`} />

            <div className="space-y-4 px-4 py-4 lg:px-6 print:p-0">
                <nav aria-label="Receipt" className="flex items-center justify-between gap-4 print:hidden">
                    <Link href={route('requests.show', receipt.id)} className="btn btn-ghost btn-sm capitalize">
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        Back to Request
                    </Link>
                    <div className="flex flex-wrap items-center gap-2">
                        {/* FR-03: the cover sheet to attach to the hardcopy (QR of the reference). */}
                        <Link href={route('requests.cover-sheet', receipt.id)} className="btn btn-sm capitalize">
                            <QrCode className="size-4" aria-hidden="true" />
                            Cover Sheet
                        </Link>
                        <button type="button" className="btn btn-primary btn-sm capitalize" onClick={() => window.print()}>
                            <Printer className="size-4" aria-hidden="true" />
                            Print
                        </button>
                    </div>
                </nav>

                <article className="card bg-base-100 border-base-300 border print:border-0">
                    <div className="card-body gap-6 p-4 md:p-6 print:p-0">
                        <header className="border-base-300 flex flex-wrap items-end justify-between gap-4 border-b pb-4">
                            <div className="space-y-1">
                                <p className="text-base-content/70 text-sm">Ministry of Health · Non-objection request</p>
                                <h1 className="text-2xl font-semibold capitalize">Submission Receipt</h1>
                            </div>

                            <section aria-label="Reference Number" className="border-base-content rounded-box border-2 px-5 py-3 text-center">
                                <p className="text-sm capitalize">Reference Number</p>
                                <p className="font-mono text-3xl font-bold tracking-wide">{receipt.reference_no}</p>
                            </section>
                        </header>

                        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4 print:grid-cols-2">
                            <Item label="Organisation">{receipt.organisation_name ?? '—'}</Item>
                            <Item label="Procurement Type">{receipt.procurement_type ?? '—'}</Item>
                            <Item label="Item" wide>
                                {receipt.item_description ?? '—'}
                            </Item>
                            <Item label="Submitted">
                                <span className="tabular-nums">{localDateTime(receipt.submitted_at)}</span>
                            </Item>
                            <Item label="Submitted by">{receipt.submitted_by ?? '—'}</Item>
                        </dl>

                        <section aria-labelledby="receipt-documents-heading" className="space-y-2">
                            <h2 id="receipt-documents-heading" className="text-lg font-semibold capitalize">
                                Required Documents Received
                            </h2>
                            {receipt.documents.length === 0 ? (
                                <p className="text-sm">None.</p>
                            ) : (
                                <div className="border-base-300 rounded-box max-w-full overflow-x-auto border print:overflow-visible">
                                    <table className="table-sm table">
                                        <thead>
                                            <tr className="capitalize">
                                                <th scope="col">Document</th>
                                                <th scope="col">File</th>
                                                <th scope="col">Version</th>
                                                <th scope="col">Uploaded</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {receipt.documents.map((doc) => (
                                                <tr key={`${doc.label}-${doc.display_name}-${doc.version}`} className="align-top">
                                                    <td>{doc.label}</td>
                                                    <td className="break-all">{doc.display_name}</td>
                                                    <td className="tabular-nums">{doc.version}</td>
                                                    <td className="tabular-nums">{localDateTime(doc.uploaded_at)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>

                        <div className="alert border-base-content bg-base-100 border-2">
                            <Mailbox className="size-5" aria-hidden="true" />
                            <p className="font-medium">{receipt.hardcopy_notice}</p>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    );
}
