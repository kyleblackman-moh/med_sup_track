import { Banknote } from 'lucide-react';

export interface FundingSummary {
    fields: { label: string; value: string }[];
    total: { label: string; value: string };
}

/**
 * Kyle, 2026-11-26: the figures CAB confirms funding on, highlighted at the
 * top of Request Details: the drug and DIN, the recommended supplier, the
 * quantity and unit price, then the money with the total cost standing out.
 * The server sends it only to a funding reviewer, with the values already
 * worked out (StaffCaseDetail::fundingSummary). Presentational only.
 */
export function FundingFigures({ summary }: { summary: FundingSummary }) {
    return (
        <section aria-labelledby="funding-figures-heading" className="card card-border bg-base-100 border-s-status-cyan border-s-4">
            <div className="card-body gap-4 p-4">
                <h2 id="funding-figures-heading" className="card-title text-base capitalize">
                    <Banknote className="text-base-content/70 size-5" aria-hidden="true" />
                    Funding Figures
                </h2>
                <div className="flex flex-wrap items-end gap-x-8 gap-y-4">
                    <dl className="grid min-w-0 flex-1 grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3 xl:grid-cols-4">
                        {summary.fields.map((field) => (
                            <div key={field.label} className="min-w-0">
                                <dt className="text-base-content/70 text-xs capitalize">{field.label}</dt>
                                <dd className="font-medium break-words tabular-nums">{field.value}</dd>
                            </div>
                        ))}
                    </dl>
                    <dl className="bg-base-200 rounded-box shrink-0 px-4 py-3">
                        <dt className="text-base-content/70 text-xs capitalize">{summary.total.label}</dt>
                        <dd className="text-2xl font-semibold tabular-nums">{summary.total.value}</dd>
                    </dl>
                </div>
            </div>
        </section>
    );
}
