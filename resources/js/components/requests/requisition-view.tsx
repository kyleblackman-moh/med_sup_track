import { type RequestDetail, type Section } from '@/types/requests';
import { COMMENTS_FIELD, COMMENTS_TAB, displayValue, REQUISITION_GROUPS, SECTION_TAB, SECTIONS, type TabId } from './fields';

const SECTION_ORDER: Section[] = ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'];

export type RequisitionContent = Pick<RequestDetail, 'requisition' | 'totals' | Section>;

/**
 * Read-only requisition: field groups, the four row sections and their totals.
 * Every derived value and total comes from the server. Shared by the vendor
 * request view and the staff case view. Each group and row section is a flat
 * bordered card (Blueprint); tables are daisyUI table-sm in their own scroll box.
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
                    <section key={group.title} className="card bg-base-100 border-base-300 min-w-0 border">
                        <div className="card-body min-w-0 gap-3 p-4">
                            <h2 className="card-title text-lg capitalize">{group.title}</h2>
                            <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                                {fields.map((field) => (
                                    <div key={field.name}>
                                        <dt className="text-base-content/70 text-sm capitalize">{field.label}</dt>
                                        <dd className="whitespace-pre-line">{displayValue(field, content.requisition[field.name])}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </section>
                );
            })}

            {showStock && (
                <p className="text-sm tabular-nums">
                    Stock on hand: {content.requisition.derived.stock_on_hand ?? '—'} · Coverage (months):{' '}
                    {content.requisition.derived.coverage_months ?? '—'}
                </p>
            )}

            {sections.map((section) => {
                const def = SECTIONS[section];

                if (content[section].length === 0) {
                    return (
                        <section key={section} className="card bg-base-100 border-base-300 min-w-0 border">
                            <div className="card-body min-w-0 gap-3 p-4">
                                <h2 className="card-title text-lg capitalize">{def.title}</h2>
                                <p className="text-base-content/70 text-sm">None recorded.</p>
                            </div>
                        </section>
                    );
                }

                return (
                    <section key={section} className="card bg-base-100 border-base-300 min-w-0 border">
                        <div className="card-body min-w-0 gap-3 p-4">
                            <h2 className="card-title text-lg capitalize">{def.title}</h2>
                            <div className="border-base-300 rounded-box max-w-full overflow-x-auto border">
                                <table className="table-sm table">
                                    <thead>
                                        <tr className="capitalize">
                                            {def.columns.map((field) => (
                                                <th key={field.name} scope="col">
                                                    {field.label}
                                                </th>
                                            ))}
                                            {def.derived.map(([key, label]) => (
                                                <th key={key} scope="col">
                                                    {label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {content[section].map((row) => (
                                            <tr key={row.id}>
                                                {def.columns.map((field) => (
                                                    <td key={field.name}>{displayValue(field, row[field.name])}</td>
                                                ))}
                                                {def.derived.map(([key]) => (
                                                    <td key={key} className="font-medium tabular-nums">
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
                                        <dt className="text-base-content/70">{label}:</dt>
                                        <dd className="font-medium tabular-nums">{content.totals[section][key] ?? '—'}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </section>
                );
            })}
        </>
    );
}
