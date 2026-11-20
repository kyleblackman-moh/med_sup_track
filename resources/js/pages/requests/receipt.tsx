import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';

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

/** Printable submission receipt. Standalone page, so only the receipt prints. */
export default function ReceiptPage({ receipt }: { receipt: Receipt }) {
    return (
        <div className="bg-background text-foreground min-h-screen print:bg-white print:text-black">
            <Head title={`Receipt ${receipt.reference_no}`} />

            <div className="mx-auto max-w-3xl space-y-6 p-6 print:max-w-none print:p-0">
                <nav className="flex items-center justify-between gap-4 print:hidden">
                    <Link
                        href={route('requests.show', receipt.id)}
                        className="text-primary focus-visible:ring-ring rounded-sm text-sm underline focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden"
                    >
                        Back to Request
                    </Link>
                    <Button type="button" onClick={() => window.print()}>
                        Print
                    </Button>
                </nav>

                <header className="border-b pb-4">
                    <p className="text-muted-foreground text-sm print:text-black">Ministry of Health · Non-objection request</p>
                    <h1 className="text-2xl font-semibold">Submission Receipt</h1>
                </header>

                <section aria-label="Reference Number" className="rounded-lg border-2 p-4 text-center">
                    <p className="text-sm">Reference Number</p>
                    <p className="font-mono text-3xl font-bold tracking-wide">{receipt.reference_no}</p>
                </section>

                <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 print:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground text-sm print:text-black">Organisation</dt>
                        <dd>{receipt.organisation_name ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-sm print:text-black">Procurement Type</dt>
                        <dd>{receipt.procurement_type ?? '—'}</dd>
                    </div>
                    <div className="sm:col-span-2 print:col-span-2">
                        <dt className="text-muted-foreground text-sm print:text-black">Item</dt>
                        <dd>{receipt.item_description ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-sm print:text-black">Submitted</dt>
                        <dd>{localDateTime(receipt.submitted_at)}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-sm print:text-black">Submitted by</dt>
                        <dd>{receipt.submitted_by ?? '—'}</dd>
                    </div>
                </dl>

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold">Required Documents Received</h2>
                    {receipt.documents.length === 0 ? (
                        <p className="text-sm">None.</p>
                    ) : (
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b">
                                    <th scope="col" className="py-1 pr-3 font-medium">
                                        Document
                                    </th>
                                    <th scope="col" className="py-1 pr-3 font-medium">
                                        File
                                    </th>
                                    <th scope="col" className="py-1 pr-3 font-medium">
                                        Version
                                    </th>
                                    <th scope="col" className="py-1 font-medium">
                                        Uploaded
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {receipt.documents.map((doc) => (
                                    <tr key={`${doc.label}-${doc.display_name}-${doc.version}`} className="border-b align-top">
                                        <td className="py-1 pr-3">{doc.label}</td>
                                        <td className="py-1 pr-3 break-all">{doc.display_name}</td>
                                        <td className="py-1 pr-3">{doc.version}</td>
                                        <td className="py-1">{localDateTime(doc.uploaded_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>

                <p className="rounded-md border p-4 font-medium">{receipt.hardcopy_notice}</p>
            </div>
        </div>
    );
}
