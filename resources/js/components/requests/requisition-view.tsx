import { type RequestDetail, type Section } from '@/types/requests';
import { COMMENTS_FIELD, COMMENTS_TAB, displayValue, REQUISITION_GROUPS, SECTION_TAB, SECTIONS, type TabId } from './fields';

const SECTION_ORDER: Section[] = ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'];

export type RequisitionContent = Pick<RequestDetail, 'requisition' | 'totals' | Section>;

/**
 * Read-only requisition: field groups, the four row sections and their totals.
 * Every derived value and total comes from the server. Shared by the vendor
 * request view and the staff case view.
 */
export function RequisitionView({ content, isPharm, tab }: { content: RequisitionContent; isPharm: boolean; tab?: TabId }) {
    // With a tab, only that tab's groups and sections (the vendor form's split); without, everything.
    const groups = [...REQUISITION_GROUPS, { title: 'Comments', fields: [COMMENTS_FIELD], tab: COMMENTS_TAB }].filter(
        (group) => tab === undefined || group.tab === tab,
    );
    const sections = SECTION_ORDER.filter((section) => (tab === undefined ? content[section].length > 0 : SECTION_TAB[section] === tab));
    const showStock = tab === undefined || tab === 'stock';

    return (
        <>
            {groups.map((group) => {
                const fields = group.fields.filter((f) => !f.pharmOnly || isPharm);

                return (
                    <section key={group.title} className="space-y-2">
                        <h2 className="text-lg font-semibold">{group.title}</h2>
                        <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            {fields.map((field) => (
                                <div key={field.name}>
                                    <dt className="text-muted-foreground text-sm">{field.label}</dt>
                                    <dd className="whitespace-pre-line">{displayValue(field, content.requisition[field.name])}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                );
            })}

            {showStock && (
                <p className="text-sm">
                    Stock on hand: {content.requisition.derived.stock_on_hand ?? '—'} · Coverage (months):{' '}
                    {content.requisition.derived.coverage_months ?? '—'}
                </p>
            )}

            {sections.map((section) => {
                const def = SECTIONS[section];

                if (content[section].length === 0) {
                    return (
                        <section key={section} className="space-y-2">
                            <h2 className="text-lg font-semibold">{def.title}</h2>
                            <p className="text-muted-foreground text-sm">None recorded.</p>
                        </section>
                    );
                }

                return (
                    <section key={section} className="space-y-2">
                        <h2 className="text-lg font-semibold">{def.title}</h2>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        {def.columns.map((field) => (
                                            <th key={field.name} scope="col" className="px-3 py-2 font-medium">
                                                {field.label}
                                            </th>
                                        ))}
                                        {def.derived.map(([key, label]) => (
                                            <th key={key} scope="col" className="px-3 py-2 font-medium">
                                                {label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {content[section].map((row) => (
                                        <tr key={row.id} className="border-t">
                                            {def.columns.map((field) => (
                                                <td key={field.name} className="px-3 py-2">
                                                    {displayValue(field, row[field.name])}
                                                </td>
                                            ))}
                                            {def.derived.map(([key]) => (
                                                <td key={key} className="px-3 py-2 font-medium">
                                                    {row.derived?.[key] ?? '—'}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                            {def.totals.map(([key, label]) => (
                                <div key={key} className="flex gap-1">
                                    <dt className="text-muted-foreground">{label}:</dt>
                                    <dd className="font-medium">{content.totals[section][key] ?? '—'}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                );
            })}
        </>
    );
}
