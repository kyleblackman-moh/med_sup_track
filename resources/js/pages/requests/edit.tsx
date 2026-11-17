import { DeleteDraftDialog } from '@/components/requests/delete-draft-dialog';
import { FieldInput } from '@/components/requests/field-input';
import {
    COMMENTS_FIELD,
    COMMENTS_TAB,
    REQUISITION_FIELDS,
    REQUISITION_GROUPS,
    SECTIONS,
    type FieldDef,
    type TabId,
} from '@/components/requests/fields';
import { FlashMessages } from '@/components/requests/flash-messages';
import { FormActions } from '@/components/requests/form-actions';
import { buildTabs, FormTabs } from '@/components/requests/form-tabs';
import { RowList } from '@/components/requests/row-list';
import { StatusBadge } from '@/components/requests/status-badge';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DocumentGroup, type FormConfig, type Readiness, type RequestDetail, type UploadRules } from '@/types/requests';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Props {
    case: RequestDetail;
    readiness: Readiness;
    config: FormConfig & { uploads: UploadRules };
    documents: DocumentGroup[];
    can: { edit: boolean };
}

type FormData = { requisition: Record<string, string> };

/** Field ids follow the server's dotted error keys: requisition.x -> requisition-x. */
const fieldId = (key: string) => key.replace(/\./g, '-');

const visible = (config: FormConfig) => (field: FieldDef) => !field.pharmOnly || config.is_pharm;

function initialData(detail: RequestDetail, config: FormConfig): FormData {
    const requisition: Record<string, string> = {};
    for (const field of REQUISITION_FIELDS.filter(visible(config))) {
        const value = detail.requisition[field.name];
        requisition[field.name] = value === null || value === undefined ? '' : String(value);
    }

    return { requisition };
}

/** Requisition fields shown on one tab. */
function tabFields(tab: TabId, config: FormConfig): FieldDef[] {
    const fields = REQUISITION_GROUPS.filter((g) => g.tab === tab).flatMap((g) => g.fields);

    return [...fields, ...(tab === COMMENTS_TAB ? [COMMENTS_FIELD] : [])].filter(visible(config));
}

function tabFromUrl(ids: TabId[]): TabId {
    if (typeof window === 'undefined') {
        return ids[0];
    }
    const wanted = new URLSearchParams(window.location.search).get('tab');

    return ids.find((id) => id === wanted) ?? ids[0];
}

/**
 * The draft form, one tab per part. Requisition fields are saved per tab
 * (Next and tab changes save first); row sections save as rows are changed.
 */
export default function Edit({ case: detail, readiness, config, documents, can }: Props) {
    const [deleting, setDeleting] = useState(false);
    const tabs = useMemo(() => buildTabs(config), [config]);
    const ids = tabs.map((t) => t.id);
    const [active, setActive] = useState<TabId>(() => tabFromUrl(ids));
    const { data, setData, put, processing, transform, isDirty, setDefaults, ...form } = useForm<FormData>(initialData(detail, config));
    const errors = form.errors as Record<string, string>;

    const index = ids.indexOf(active);
    const tab = tabs[index];
    const fields = tabFields(active, config);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        { title: 'Edit draft', href: route('requests.edit', detail.id) },
    ];

    const show = (next: TabId) => {
        setActive(next);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', next);
        window.history.replaceState(window.history.state, '', url);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /** Save this tab's fields (only if changed), then run `then`. Validation errors keep the user here. */
    const saveThen = (then?: () => void) => {
        if (!isDirty) {
            then?.();

            return;
        }
        const names = fields.map((f) => f.name);
        transform((d) => ({ requisition: Object.fromEntries(names.map((name) => [name, d.requisition[name] ?? ''])) }));
        put(route('requests.update', detail.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setDefaults();
                then?.();
            },
        });
    };

    const goNext = () => saveThen(() => (index < tabs.length - 1 ? show(ids[index + 1]) : router.visit(route('requests.show', detail.id))));
    const goBack = () => saveThen(() => index > 0 && show(ids[index - 1]));
    const select = (next: TabId) => next !== active && saveThen(() => show(next));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        goNext();
    };

    const errorEntries = Object.entries(errors);

    // A render function, not a component: a component defined here would remount (and lose focus) on every keystroke.
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

            <div className="mx-auto w-full max-w-5xl space-y-4 px-4 pt-4">
                {/* Status only: the form's actions are in the footer row at the bottom (CLAUDE.md, UI). */}
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{detail.requisition.item_description || 'New request'}</h1>
                        <p className="text-muted-foreground text-sm">
                            {config.procurement_type.name}
                            {detail.reference_no && (
                                <>
                                    {' · '}
                                    <span className="font-mono text-xs">{detail.reference_no}</span>
                                </>
                            )}
                        </p>
                    </div>
                    <StatusBadge label={detail.status_label} group={detail.status_group} />
                </header>

                <FormTabs tabs={tabs} active={active} readiness={readiness} onSelect={select} />

                <FlashMessages />

                <div aria-live="assertive" className="empty:hidden">
                    {errorEntries.length > 0 && (
                        <div
                            role="alert"
                            className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
                        >
                            <p className="font-medium">This tab was not saved. Please fix the following:</p>
                            <ul className="mt-1 list-disc space-y-0.5 pl-5">
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

                <div role="tabpanel" id={`panel-${active}`} aria-labelledby={`tab-${active}`} className="space-y-6 pb-4">
                    {fields.length > 0 && (
                        <form onSubmit={submit} noValidate className="space-y-6">
                            {REQUISITION_GROUPS.filter((g) => g.tab === active).map((group) => (
                                <fieldset key={group.title} className="space-y-3">
                                    <legend className="text-lg font-semibold">{group.title}</legend>
                                    <div className="grid gap-4 sm:grid-cols-2">{group.fields.filter(visible(config)).map(requisitionInput)}</div>
                                </fieldset>
                            ))}

                            {active === 'stock' && (
                                <p className="text-muted-foreground text-sm">
                                    Stock on hand:{' '}
                                    <span className="text-foreground font-medium">{detail.requisition.derived.stock_on_hand ?? '—'}</span>
                                    {' · '}Coverage (months):{' '}
                                    <span className="text-foreground font-medium">{detail.requisition.derived.coverage_months ?? '—'}</span>
                                    <span className="block text-xs">Updated when you save.</span>
                                </p>
                            )}

                            {active === COMMENTS_TAB && requisitionInput(COMMENTS_FIELD)}

                            {/* Enter in a field moves on, like Next. */}
                            <button type="submit" hidden aria-hidden tabIndex={-1} />
                        </form>
                    )}

                    {tab.sections.map((section) => (
                        <RowList
                            key={section}
                            caseId={detail.id}
                            section={section}
                            def={SECTIONS[section]}
                            rows={detail[section]}
                            totals={detail.totals[section]}
                            required={config.required_sections.includes(section)}
                            cap={config.row_caps[section]}
                            config={config}
                        />
                    ))}

                    {active === 'documents' && <SupportingDocuments caseId={detail.id} groups={documents} rules={config.uploads} />}
                </div>

                <FormActions
                    isFirst={index === 0}
                    isLast={index === tabs.length - 1}
                    dirty={isDirty}
                    processing={processing}
                    onBack={goBack}
                    onSave={() => saveThen()}
                    onNext={goNext}
                    onDelete={can.edit ? () => setDeleting(true) : undefined}
                />
            </div>
            <DeleteDraftDialog caseId={deleting ? detail.id : null} onClose={() => setDeleting(false)} />
        </AppLayout>
    );
}
