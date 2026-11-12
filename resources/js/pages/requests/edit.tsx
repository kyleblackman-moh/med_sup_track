import { FieldInput } from '@/components/requests/field-input';
import { COMMENTS_FIELD, REQUISITION_FIELDS, REQUISITION_GROUPS, SECTIONS, type FieldDef } from '@/components/requests/fields';
import { FlashMessages } from '@/components/requests/flash-messages';
import { ReadinessPanel } from '@/components/requests/readiness-panel';
import { SectionTable, type EditableRow } from '@/components/requests/section-table';
import { StatusBadge } from '@/components/requests/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type FormConfig, type Readiness, type RequestDetail, type Section } from '@/types/requests';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props {
    case: RequestDetail;
    readiness: Readiness;
    config: FormConfig;
    can: { edit: boolean };
}

type FormData = {
    requisition: Record<string, string>;
} & Record<Section, EditableRow[]>;

/** Field ids follow the server's dotted error keys: requisition.x -> requisition-x, quotations.0.x -> quotations-0-x. */
const fieldId = (key: string) => key.replace(/\./g, '-');

function visibleRequisitionFields(config: FormConfig): FieldDef[] {
    return REQUISITION_FIELDS.filter((f) => !f.pharmOnly || config.is_pharm);
}

function initialData(detail: RequestDetail, config: FormConfig): FormData {
    const requisition: Record<string, string> = {};
    for (const field of visibleRequisitionFields(config)) {
        const value = detail.requisition[field.name];
        requisition[field.name] = value === null || value === undefined ? '' : String(value);
    }

    const sections = {} as Record<Section, EditableRow[]>;
    for (const section of Object.keys(SECTIONS) as Section[]) {
        sections[section] = detail[section].map((saved) => {
            const row: EditableRow = { _key: `saved-${saved.id}` };
            for (const field of SECTIONS[section].columns) {
                const value = saved[field.name];
                row[field.name] = field.kind === 'boolean' ? value === true : value === null || value === undefined ? '' : String(value);
            }

            return row;
        });
    }

    return { requisition, ...sections };
}

export default function Edit({ case: detail, readiness, config }: Props) {
    const allowed = config.allowed_sections;
    const { data, setData, put, processing, transform, ...form } = useForm<FormData>(initialData(detail, config));
    const errors = form.errors as Record<string, string>;
    const errorEntries = Object.entries(errors);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        { title: 'Edit draft', href: route('requests.edit', detail.id) },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        // Send the requisition and the allowed sections only, without client-only row keys.
        transform((d) => ({
            requisition: d.requisition,
            ...Object.fromEntries(allowed.map((section) => [section, d[section].map(({ _key, ...row }) => (void _key, row))])),
        }));
        put(route('requests.update', detail.id), { preserveScroll: true });
    };

    const requisitionInput = (field: FieldDef) => (
        <FieldInput
            key={field.name}
            id={fieldId(`requisition.${field.name}`)}
            field={field}
            value={data.requisition[field.name] ?? ''}
            error={errors[`requisition.${field.name}`]}
            config={config}
            onChange={(value) => setData('requisition', { ...data.requisition, [field.name]: String(value) })}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit draft request" />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{detail.requisition.item_description || 'New request'}</h1>
                        <p className="text-muted-foreground text-sm">
                            {config.procurement_type.name} · {detail.reference_no ?? 'Draft'}
                        </p>
                    </div>
                    <StatusBadge label={detail.status_label} group={detail.status_group} />
                </header>

                <FlashMessages />

                <div aria-live="assertive" className="empty:hidden">
                    {errorEntries.length > 0 && (
                        <div
                            role="alert"
                            className="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
                        >
                            <p className="font-medium">Your draft was not saved. Please fix the following:</p>
                            <ul className="mt-2 list-disc space-y-1 pl-5">
                                {errorEntries.map(([key, message]) => (
                                    <li key={key}>
                                        <a href={`#${fieldId(key)}`} className="underline">
                                            {message}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>

                <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
                    <form onSubmit={submit} className="space-y-8" noValidate>
                        {REQUISITION_GROUPS.map((group) => {
                            const fields = group.fields.filter((f) => !f.pharmOnly || config.is_pharm);

                            return (
                                <section key={group.title} aria-labelledby={fieldId(`group.${group.title}`)} className="space-y-4">
                                    <h2 id={fieldId(`group.${group.title}`)} className="text-lg font-semibold">
                                        {group.title}
                                    </h2>
                                    <div className="grid gap-4 sm:grid-cols-2">{fields.map(requisitionInput)}</div>
                                </section>
                            );
                        })}

                        {allowed.map((section) => (
                            <SectionTable
                                key={section}
                                section={section}
                                def={SECTIONS[section]}
                                rows={data[section]}
                                savedRows={detail[section]}
                                totals={detail.totals[section]}
                                required={config.required_sections.includes(section)}
                                cap={config.row_caps[section]}
                                config={config}
                                errors={errors}
                                onChange={(rows) => setData(section, rows)}
                            />
                        ))}

                        <section aria-labelledby="comments-heading" className="space-y-4">
                            <h2 id="comments-heading" className="text-lg font-semibold">
                                Comments
                            </h2>
                            {requisitionInput(COMMENTS_FIELD)}
                        </section>

                        <div className="flex items-center gap-4 border-t pt-4">
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving…' : 'Save draft'}
                            </Button>
                            <Link href={route('requests.show', detail.id)} className="text-sm underline">
                                View request
                            </Link>
                        </div>
                    </form>

                    <aside className="space-y-4">
                        <ReadinessPanel readiness={readiness} />
                        <p className="text-muted-foreground text-xs">
                            Stock on hand: {detail.requisition.derived.stock_on_hand ?? '—'} · Coverage (months):{' '}
                            {detail.requisition.derived.coverage_months ?? '—'}
                        </p>
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
