// Slice V2-3: the one read-only request summary (App\Http\Resources\Requests\RequestSummary).
// Every value is display text from the server.

export interface SummaryFieldsBlock {
    type: 'fields';
    items: { label: string; value: string }[];
}

export interface SummaryTableBlock {
    type: 'table';
    caption: string;
    columns: { key: string; label: string }[];
    /** Each row's label ("Supplier row 1") and its cells by column key. */
    rows: { label: string; cells: Record<string, string> }[];
    /** "None entered" when there are no rows, else null. */
    empty: string | null;
}

export interface SummaryWarningBlock {
    type: 'warning';
    title: string;
    lines: string[];
}

export type SummaryBlock = SummaryFieldsBlock | SummaryTableBlock | SummaryWarningBlock;

export interface RequestSummaryView {
    type_label: string;
    /** The type's visible sections, in form order, numbered among the visible ones. */
    sections: { key: string; number: number; label: string; blocks: SummaryBlock[] }[];
}
