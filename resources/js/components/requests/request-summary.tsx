import { type RequestSummaryView, type SummaryBlock } from '@/types/summary';
import { TriangleAlert } from 'lucide-react';
import { useId } from 'react';

type SummarySection = RequestSummaryView['sections'][number];

/**
 * V2-3: the ONE read-only request summary, shown by the Review step, the
 * vendor's request page and the staff case page. Sections, numbering, labels
 * and every value come from the server (RequestSummary): this only lays them
 * out. Stacked bordered cards (Blueprint, flat), definition lists and compact
 * read-only tables; no inputs. Print-friendly: nothing sticky, and tables wrap
 * instead of overflowing the page.
 */
export function RequestSummary({ summary, idPrefix = 'summary' }: { summary: RequestSummaryView; idPrefix?: string }) {
    return (
        <div className="space-y-4">
            {summary.sections.map((section) => (
                <Section key={section.key} id={`${idPrefix}-${section.key}`} section={section} />
            ))}
        </div>
    );
}

function Section({ id, section }: { id: string; section: SummarySection }) {
    const headingId = useId();

    return (
        <section id={id} aria-labelledby={headingId} className="card bg-base-100 border-base-300 min-w-0 border print:break-inside-avoid-page">
            <div className="card-body min-w-0 gap-4 p-4 md:p-5">
                <h2 id={headingId} className="card-title text-base capitalize">
                    {`${section.number}. ${section.label}`}
                </h2>
                <div className="space-y-5">
                    {section.blocks.map((block, i) => (
                        <Block key={i} block={block} />
                    ))}
                </div>
            </div>
        </section>
    );
}

function Block({ block }: { block: SummaryBlock }) {
    if (block.type === 'fields') {
        return (
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
                {block.items.map((item) => (
                    <div key={item.label} className="min-w-0">
                        <dt className="text-base-content/70 text-[13px] capitalize">{item.label}</dt>
                        <dd className="text-sm break-words whitespace-pre-line tabular-nums">{item.value}</dd>
                    </div>
                ))}
            </dl>
        );
    }

    if (block.type === 'warning') {
        return (
            <div className="alert alert-warning alert-soft items-start text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div className="space-y-1">
                    <p className="font-semibold">{block.title}</p>
                    <ul className="space-y-0.5">
                        {block.lines.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                </div>
            </div>
        );
    }

    return (
        <div className="min-w-0 space-y-2">
            <h3 className="text-sm font-semibold capitalize">{block.caption}</h3>
            {block.empty !== null ? (
                <p className="text-base-content/70 text-sm">{block.empty}</p>
            ) : (
                // A wide table scrolls inside its own bordered box on screen; in print it wraps.
                <div className="border-base-300 rounded-box max-w-full overflow-x-auto border print:overflow-visible">
                    <table className="table-sm table text-[13px]">
                        <caption className="sr-only">{block.caption}</caption>
                        <thead>
                            <tr className="capitalize">
                                <th scope="col" className="whitespace-nowrap">
                                    <span className="sr-only">Row</span>
                                </th>
                                {block.columns.map((column) => (
                                    <th key={column.key} scope="col">
                                        {column.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {block.rows.map((row) => (
                                <tr key={row.label}>
                                    <th scope="row" className="text-left font-medium whitespace-nowrap">
                                        {row.label}
                                    </th>
                                    {block.columns.map((column) => (
                                        <td key={column.key} className="break-words tabular-nums">
                                            {row.cells[column.key]}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
