import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { type ReactNode } from 'react';

interface Sheet {
    id: string;
    reference_no: string;
    organisation_name: string | null;
    procurement_type: string | null;
    item_description: string | null;
    submitted_at: string | null;
    /** The server's inline SVG of a QR code holding the reference number only. */
    qr_svg: string;
    instruction: string;
}

function localDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: 'America/Port_of_Spain', dateStyle: 'long', timeStyle: 'short' }) : '—';
}

function Item({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-base-content/70 text-sm capitalize">{label}</dt>
            <dd className="font-medium break-words">{children}</dd>
        </div>
    );
}

/**
 * FR-03: the hardcopy cover sheet. A standalone printable page (like the
 * receipt): the browser's print dialog saves it as PDF. The reference leads
 * in a bordered stamp with its QR code beside it (scanned into the
 * dashboard's reference lookup), then the case facts and the delivery
 * instruction. Flat, so the print matches the screen.
 */
export default function CoverSheetPage({ sheet, back_url: backUrl }: { sheet: Sheet; back_url: string }) {
    return (
        <div className="bg-base-200 text-base-content print:bg-base-100 min-h-screen">
            <Head title={`Cover Sheet ${sheet.reference_no}`} />

            <div className="space-y-4 px-4 py-4 lg:px-6 print:p-0">
                <nav aria-label="Cover sheet" className="flex flex-wrap items-center justify-between gap-2 print:hidden">
                    <Link href={backUrl} className="btn btn-ghost btn-sm capitalize">
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        Back
                    </Link>
                    <button type="button" className="btn btn-primary btn-sm capitalize" onClick={() => window.print()}>
                        <Printer className="size-4" aria-hidden="true" />
                        Print or Save as PDF
                    </button>
                </nav>

                <article className="card bg-base-100 border-base-300 border print:border-0">
                    <div className="card-body gap-6 p-4 md:p-6 print:p-0">
                        <header className="border-base-300 space-y-1 border-b pb-4">
                            <p className="text-base-content/70 text-sm">Ministry of Health · Office of the Chief Medical Officer</p>
                            <h1 className="text-2xl font-semibold capitalize">Hardcopy Cover Sheet</h1>
                        </header>

                        <section
                            aria-label="Reference Number"
                            className="border-base-content rounded-box flex flex-wrap items-center justify-between gap-6 border-2 p-5"
                        >
                            <div className="space-y-1">
                                <p className="text-sm capitalize">Reference Number</p>
                                <p className="font-mono text-4xl font-bold tracking-wide break-all">{sheet.reference_no}</p>
                                <p className="text-base-content/70 text-helper">Scan the code to look the case up.</p>
                            </div>
                            <div
                                role="img"
                                aria-label={`QR code for reference ${sheet.reference_no}`}
                                className="bg-base-100 size-44 shrink-0 [&_svg]:size-full"
                                // Server-built SVG (CoverSheet::qr): no user input reaches the markup except the reference, encoded as modules.
                                dangerouslySetInnerHTML={{ __html: sheet.qr_svg }}
                            />
                        </section>

                        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 print:grid-cols-2">
                            <Item label="Vendor">{sheet.organisation_name ?? '—'}</Item>
                            <Item label="Procurement Type">{sheet.procurement_type ?? '—'}</Item>
                            <Item label="Item">{sheet.item_description ?? '—'}</Item>
                            <Item label="Submitted">
                                <span className="tabular-nums">{localDateTime(sheet.submitted_at)}</span>
                            </Item>
                        </dl>

                        <p className="border-base-content rounded-box border-2 p-4 font-medium">{sheet.instruction}</p>

                        <dl className="border-base-300 grid gap-x-6 gap-y-6 border-t pt-4 sm:grid-cols-2 print:grid-cols-2">
                            <div>
                                <dt className="text-base-content/70 text-sm capitalize">Received By (Office Use)</dt>
                                <dd className="border-base-content mt-6 border-b" aria-hidden="true" />
                            </div>
                            <div>
                                <dt className="text-base-content/70 text-sm capitalize">Date Received (Office Use)</dt>
                                <dd className="border-base-content mt-6 border-b" aria-hidden="true" />
                            </div>
                        </dl>
                    </div>
                </article>
            </div>
        </div>
    );
}
