import { SectionPanel } from '@/components/ui/section-panel';
import { type RequestSummaryView, type SummaryBlock } from '@/types/summary';
import { TriangleAlert } from 'lucide-react';

/**
 * V2-3: the ONE read-only request summary, shown by the Review step, the
 * vendor's request page and the staff case page. Sections, numbering, labels
 * and every value come from the server (RequestSummary): this only lays them
 * out. Stacked section panels, definition lists and compact read-only tables;
 * no inputs. Print-friendly: nothing sticky, and tables wrap instead of
 * overflowing the page.
 */
export function RequestSummary({ summary, idPrefix = 'summary' }: { summary: RequestSummaryView; idPrefix?: string }) {
    return (
        <div className="space-y-6">
            {summary.sections.map((section) => (
                <SectionPanel
                    key={section.key}
                    id={`${idPrefix}-${section.key}`}
                    title={`${section.number}. ${section.label}`}
                    titleClassName="text-base font-semibold"
                    className="print:break-inside-avoid-page"
                >
                    <div className="space-y-5">
                        {section.blocks.map((block, i) => (
                            <Block key={i} block={block} />
                        ))}
                    </div>
                </SectionPanel>
            ))}
        </div>
    );
}

function Block({ block }: { block: SummaryBlock }) {
    if (block.type === 'fields') {
        return (
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
                {block.items.map((item) => (
                    <div key={item.label} className="min-w-0">
                        <dt className="text-muted-foreground text-[13px]">{item.label}</dt>
                        <dd className="text-sm break-words whitespace-pre-line tabular-nums">{item.value}</dd>
                    </div>
                ))}
            </dl>
        );
    }

    if (block.type === 'warning') {
        return (
            <div className="bg-status-warning text-status-warning-foreground border-status-warning-foreground/30 flex gap-3 rounded-lg border p-4 text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
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
        <div className="space-y-2">
            <h3 className="text-sm font-semibold">{block.caption}</h3>
            {block.empty !== null ? (
                <p className="text-muted-foreground text-sm">{block.empty}</p>
            ) : (
                // A wide table scrolls inside its own bordered box on screen; in print it wraps.
                <div className="overflow-x-auto rounded-lg border print:overflow-visible">
                    <table className="w-full text-left text-[13px]">
                        <caption className="sr-only">{block.caption}</caption>
                        <thead className="bg-subtle text-muted-foreground">
                            <tr>
                                <th scope="col" className="px-3 py-2 font-medium whitespace-nowrap">
                                    <span className="sr-only">Row</span>
                                </th>
                                {block.columns.map((column) => (
                                    <th key={column.key} scope="col" className="px-3 py-2 font-medium">
                                        {column.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-divider divide-y">
                            {block.rows.map((row) => (
                                <tr key={row.label}>
                                    <th scope="row" className="px-3 py-2 text-left font-medium whitespace-nowrap">
                                        {row.label}
                                    </th>
                                    {block.columns.map((column) => (
                                        <td key={column.key} className="px-3 py-2 break-words tabular-nums">
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
