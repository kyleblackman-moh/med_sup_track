import { COMMENTS_FIELD, REQUISITION_GROUPS, SECTIONS, displayValue } from '@/components/requests/fields';
import { FlashMessages } from '@/components/requests/flash-messages';
import { StatusBadge } from '@/components/requests/status-badge';
import { SubmitPanel } from '@/components/requests/submit-panel';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DocumentGroup, type Readiness, type RequestDetail, type Section } from '@/types/requests';
import { Head, Link } from '@inertiajs/react';

const SECTION_ORDER: Section[] = ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'];

interface Props {
    case: RequestDetail;
    documents: DocumentGroup[];
    readiness: Readiness | null;
    can: { submit: boolean };
}

export default function Show({ case: detail, documents, readiness, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        { title: detail.reference_no ?? 'Draft', href: route('requests.show', detail.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={detail.reference_no ?? 'Request'} />

            <div className="mx-auto w-full max-w-6xl space-y-8 p-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{detail.item_description ?? 'Request'}</h1>
                        <p className="text-muted-foreground text-sm">
                            {detail.procurement_type.name} · {detail.reference_no ?? 'Draft'}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <StatusBadge label={detail.status_label} group={detail.status_group} />
                        {detail.can.edit && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('requests.edit', detail.id)}>Edit draft</Link>
                            </Button>
                        )}
                        {detail.reference_no && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('requests.receipt', detail.id)}>Receipt</Link>
                            </Button>
                        )}
                    </div>
                </header>

                <FlashMessages />

                {readiness && <SubmitPanel caseId={detail.id} readiness={readiness} canSubmit={can.submit} />}

                {[...REQUISITION_GROUPS, { title: 'Comments', fields: [COMMENTS_FIELD] }].map((group) => {
                    const fields = group.fields.filter((f) => !f.pharmOnly || detail.procurement_type.category === 'PHARM');

                    return (
                        <section key={group.title} className="space-y-2">
                            <h2 className="text-lg font-semibold">{group.title}</h2>
                            <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                                {fields.map((field) => (
                                    <div key={field.name}>
                                        <dt className="text-muted-foreground text-sm">{field.label}</dt>
                                        <dd className="whitespace-pre-line">{displayValue(field, detail.requisition[field.name])}</dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    );
                })}

                <p className="text-sm">
                    Stock on hand: {detail.requisition.derived.stock_on_hand ?? '—'} · Coverage (months):{' '}
                    {detail.requisition.derived.coverage_months ?? '—'}
                </p>

                {SECTION_ORDER.filter((section) => detail[section].length > 0).map((section) => {
                    const def = SECTIONS[section];

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
                                        {detail[section].map((row) => (
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
                                        <dd className="font-medium">{detail.totals[section][key] ?? '—'}</dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    );
                })}

                <SupportingDocuments caseId={detail.id} groups={documents} readOnly />
            </div>
        </AppLayout>
    );
}
