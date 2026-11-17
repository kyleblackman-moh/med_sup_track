import { type AdHocConfig, type AdHocDetail, type ChildSection, type SectionStatus } from '@/types/ad-hoc';
import { type FormState, type LocalRow, type Value } from './form-state';

/** What every section component gets from the page. */
export interface FormApi {
    state: FormState;
    detail: AdHocDetail;
    config: AdHocConfig;
    setField: (name: string, value: Value) => void;
    setCell: (section: ChildSection, rowKey: string, name: string, value: Value) => void;
    addRow: (section: ChildSection) => void;
    removeRow: (section: ChildSection, rowKey: string) => void;
    /** Marks one quotation as recommended and clears the others (exactly one). */
    chooseRecommended: (rowKey: string) => void;
    /** A request field's problem: a rejected save first, then (after Review) the server's readiness issue. */
    fieldError: (name: string) => string | undefined;
    cellError: (section: ChildSection, row: LocalRow, index: number, name: string) => string | undefined;
    /** A table-level problem (key "<section>" or, for quotations, "quotations.recommended"). */
    tableError: (key: string) => string | undefined;
}

/** Text value of a form field ('' for null or a checkbox). */
export const text = (value: Value | undefined): string => (typeof value === 'string' ? value : '');

interface ErrorSources {
    saveErrors: Record<string, string>;
    sections: SectionStatus[];
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
