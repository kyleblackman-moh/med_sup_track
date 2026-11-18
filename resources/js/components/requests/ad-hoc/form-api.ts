import { type AdHocConfig, type AdHocDetail, type ChildSection, type FormSectionKey, type RequestFormView, type SectionIssue } from '@/types/ad-hoc';
import { type FormState, type LocalRow, type Value } from './form-state';

/** What every section component gets from the page. */
export interface FormApi {
    state: FormState;
    detail: AdHocDetail;
    config: AdHocConfig;
    /** V2-2: the server's sections, display strings and expiry warning. */
    form: RequestFormView;
    setField: (name: string, value: Value) => void;
    setVariation: (name: string, value: Value) => void;
    /** V2-5: answers the previous-request question (saved by the autosave). */
    setPreviousRequest: (value: boolean) => void;
    setCell: (section: ChildSection, rowKey: string, name: string, value: Value) => void;
    addRow: (section: ChildSection) => void;
    removeRow: (section: ChildSection, rowKey: string) => void;
    /** Marks one quotation as recommended and clears the others (exactly one). */
    chooseRecommended: (rowKey: string) => void;
    /**
     * The server's required map, inverted: true where the field may stay empty,
     * false where it must be filled in (the control then carries
     * aria-required), undefined where the map does not name the field. V2-4b:
     * labels no longer say "(optional)".
     */
    optional: (section: FormSectionKey, field: string) => boolean | undefined;
    /** A request field's problem: a rejected save first, then (after Review) the server's readiness issue. */
    fieldError: (name: string) => string | undefined;
    cellError: (section: ChildSection, row: LocalRow, index: number, name: string) => string | undefined;
    /** A problem by its full key ("tender_awards", "quotations.recommended", "variation.effective_date"). */
    tableError: (key: string) => string | undefined;
}

/** Text value of a form field ('' for null or a checkbox). */
export const text = (value: Value | undefined): string => (typeof value === 'string' ? value : '');

/** The server's display values for a saved row, or undefined for an unsaved one. */
export function rowDisplay<T extends { id: number }>(rows: T[], id: number | null): T | undefined {
    return id === null ? undefined : rows.find((row) => row.id === id);
}

interface ErrorSources {
    saveErrors: Record<string, string>;
    /** Sections with the server's issues (request_sections). */
    sections: { issues: SectionIssue[] }[];
    showIssues: boolean;
}

/** Builds the three error lookups from the save errors and (after Review) the server's issues. */
export function errorLookups({ saveErrors, sections, showIssues }: ErrorSources) {
    const issues = showIssues ? sections.flatMap((section) => section.issues) : [];

    return {
        fieldError: (name: string) => saveErrors[`requisition.${name}`] ?? issues.find((issue) => issue.key === `requisition.${name}`)?.message,
        cellError: (section: ChildSection, row: LocalRow, index: number, name: string) =>
            saveErrors[`${section}.${index}.${name}`] ??
            (row.id !== null ? issues.find((issue) => issue.row_id === row.id && issue.field === `${section}.${name}`)?.message : undefined),
        tableError: (key: string) => saveErrors[key] ?? issues.find((issue) => issue.key === key)?.message,
    };
}
