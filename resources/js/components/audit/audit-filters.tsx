import { type AuditFilters, type AuditOptions, EMPTY_FILTERS } from '@/components/audit/types';
import { CalendarRange, CircleAlert, Filter, Search, X } from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';

const LABEL = 'label text-base-content text-sm font-medium capitalize';
const FIELDS: (keyof AuditFilters)[] = ['actor', 'category', 'action', 'case', 'from', 'to', 'outcome'];

interface Props {
    filters: AuditFilters;
    options: AuditOptions;
    errors: Partial<Record<string, string>>;
    onApply: (filters: AuditFilters) => void;
}

export function hasActiveAuditFilters(filters: AuditFilters): boolean {
    return FIELDS.some((key) => filters[key] !== '');
}

/**
 * IT-ADMIN: the audit log filters, a GET form. Nothing applies until
 * Apply Filters (typed actor and case values are exact matches on the
 * server). The server reports a bad filter; the error is shown here and the
 * form keeps what was typed. Dates are Port of Spain days.
 */
export function AuditFiltersForm({ filters, options, errors, onApply }: Props) {
    const [draft, setDraft] = useState<AuditFilters>(filters);

    // Follow the URL (Clear Filters, back button, a redirect after a bad filter).
    useEffect(() => setDraft(filters), [filters]);

    const actions = useMemo(
        () => (draft.category === '' ? options.actions : options.actions.filter((a) => a.category === draft.category)),
        [draft.category, options.actions],
    );

    const set = (key: keyof AuditFilters, value: string) =>
        setDraft((d) => {
            const next = { ...d, [key]: value };
            // An action outside the chosen category would match nothing.
            if (key === 'category' && value !== '' && d.action !== '' && !options.actions.some((a) => a.value === d.action && a.category === value)) {
                next.action = '';
            }
            return next;
        });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        onApply({ ...draft, actor: draft.actor.trim(), case: draft.case.trim() });
    };

    const fieldErrors = FIELDS.map((key) => errors[key]).filter((m): m is string => Boolean(m));
    const formError = [...fieldErrors, errors.cursor].filter(Boolean);
    const invalid = (key: keyof AuditFilters) => (errors[key] ? true : undefined);

    return (
        <form
            onSubmit={submit}
            role="search"
            aria-label="Filter the audit log"
            className="bg-base-100 border-base-300 rounded-box grid gap-4 border p-4"
            noValidate
        >
            {formError.length > 0 && (
                <div role="alert" className="alert alert-soft alert-error items-start text-sm">
                    <CircleAlert className="size-5" aria-hidden="true" />
                    <div>
                        <p className="font-semibold">These filters could not be applied.</p>
                        {formError.map((m) => (
                            <p key={m}>{m}</p>
                        ))}
                    </div>
                </div>
            )}

            <div className="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
                <fieldset className="fieldset py-0">
                    <label className={LABEL} htmlFor="audit-actor">
                        Actor
                    </label>
                    <label className="input input-sm w-full" aria-invalid={invalid('actor')}>
                        <Search className="size-4 opacity-60" aria-hidden="true" />
                        <input
                            id="audit-actor"
                            type="text"
                            className="grow"
                            placeholder="Email, user ID, system or anonymous"
                            value={draft.actor}
                            maxLength={255}
                            onChange={(e) => set('actor', e.target.value)}
                            aria-invalid={invalid('actor')}
                            aria-describedby="audit-actor-hint"
                        />
                    </label>
                    <p id="audit-actor-hint" className="label text-helper">
                        Exact email or user ID.
                    </p>
                </fieldset>

                <fieldset className="fieldset py-0">
                    <label className={LABEL} htmlFor="audit-case">
                        Case Reference
                    </label>
                    <input
                        id="audit-case"
                        type="text"
                        className="input input-sm w-full font-mono"
                        placeholder="NR-…"
                        value={draft.case}
                        maxLength={40}
                        onChange={(e) => set('case', e.target.value)}
                        aria-invalid={invalid('case')}
                        aria-describedby="audit-case-hint"
                    />
                    <p id="audit-case-hint" className="label text-helper">
                        Any status, drafts included.
                    </p>
                </fieldset>

                <fieldset className="fieldset py-0">
                    <label className={LABEL} htmlFor="audit-category">
                        Category
                    </label>
                    <select
                        id="audit-category"
                        className="select select-sm w-full"
                        value={draft.category}
                        onChange={(e) => set('category', e.target.value)}
                        aria-invalid={invalid('category')}
                    >
                        <option value="">All categories</option>
                        {options.categories.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                </fieldset>

                <fieldset className="fieldset py-0">
                    <label className={LABEL} htmlFor="audit-action">
                        Action
                    </label>
                    <select
                        id="audit-action"
                        className="select select-sm w-full font-mono"
                        value={draft.action}
                        onChange={(e) => set('action', e.target.value)}
                        aria-invalid={invalid('action')}
                    >
                        <option value="">All actions</option>
                        {actions.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                </fieldset>

                <fieldset className="fieldset py-0 sm:col-span-2">
                    <legend className="fieldset-legend text-sm capitalize">
                        <CalendarRange className="size-4" aria-hidden="true" />
                        Date Range (Port of Spain)
                    </legend>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1">
                            <label className={LABEL} htmlFor="audit-from">
                                From
                            </label>
                            <input
                                id="audit-from"
                                type="date"
                                className="input input-sm w-full tabular-nums"
                                value={draft.from}
                                max={draft.to || undefined}
                                onChange={(e) => set('from', e.target.value)}
                                aria-invalid={invalid('from')}
                            />
                        </div>
                        <div className="grid gap-1">
                            <label className={LABEL} htmlFor="audit-to">
                                To
                            </label>
                            <input
                                id="audit-to"
                                type="date"
                                className="input input-sm w-full tabular-nums"
                                value={draft.to}
                                min={draft.from || undefined}
                                onChange={(e) => set('to', e.target.value)}
                                aria-invalid={invalid('to')}
                            />
                        </div>
                    </div>
                </fieldset>

                <fieldset className="fieldset py-0">
                    <label className={LABEL} htmlFor="audit-outcome">
                        Outcome
                    </label>
                    <select
                        id="audit-outcome"
                        className="select select-sm w-full"
                        value={draft.outcome}
                        onChange={(e) => set('outcome', e.target.value)}
                        aria-invalid={invalid('outcome')}
                    >
                        <option value="">Allowed and denied</option>
                        {options.outcomes.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                </fieldset>
            </div>

            <div className="flex flex-wrap justify-end gap-2">
                {(hasActiveAuditFilters(filters) || hasActiveAuditFilters(draft)) && (
                    <button type="button" className="btn btn-ghost btn-sm capitalize" onClick={() => onApply(EMPTY_FILTERS)}>
                        <X className="size-4" aria-hidden="true" />
                        Clear Filters
                    </button>
                )}
                <button type="submit" className="btn btn-primary btn-sm capitalize">
                    <Filter className="size-4" aria-hidden="true" />
                    Apply Filters
                </button>
            </div>
        </form>
    );
}
