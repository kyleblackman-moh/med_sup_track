/**
 * FORM-COMPACT-1 (1): the ONE field-width scale of the request form. Every
 * field and every entry-row cell on the vendor form takes its width from
 * here, by the kind of value it holds and how long it usually is:
 *
 *   xs    short numbers and dates: quantities, prices, unit cost, lead time, dates
 *   sm    short text and codes: DIN, strength, dosage form, unit of measure,
 *         contract reference, a choice (select)
 *   md    names: supplier, manufacturer, brand, country, location
 *   lg    long text on one line: item description, remarks, a question
 *   full  the whole row: text areas (reasons, notes), tables, notices
 *
 * Stacked fields sit in a FieldGrid (the card's width decides the columns, by
 * container query, so the sidebar state never breaks a row): one column below
 * 36rem, 6 columns from 36rem, 12 from 56rem. FIELD_WIDTH is the span of each
 * size. In an entry row (one line of inputs) ENTRY_WIDTH is the same scale as
 * a flex minimum; md and lg grow to fill the line.
 */
export type FieldWidth = 'xs' | 'sm' | 'md' | 'lg' | 'full';

/** The grid the stacked fields sit in (inside a `@container`, see FieldGrid). Labels stay above inputs. */
export const FIELD_GRID = 'grid grid-cols-1 items-start gap-x-5 gap-y-4 @min-[36rem]:grid-cols-6 @min-[56rem]:grid-cols-12';

/**
 * Column spans. At the 1280px viewport with the sidebar open the card is about
 * 940px: xs and sm are 220px (four a row), md 300px, lg 460px. From a 72rem
 * card (about 1536px) xs narrows to two of twelve columns (175px or more).
 */
export const FIELD_WIDTH: Record<FieldWidth, string> = {
    xs: 'col-span-full @min-[36rem]:col-span-2 @min-[56rem]:col-span-3 @min-[72rem]:col-span-2',
    sm: 'col-span-full @min-[36rem]:col-span-2 @min-[56rem]:col-span-3',
    md: 'col-span-full @min-[36rem]:col-span-3 @min-[56rem]:col-span-4',
    lg: 'col-span-full @min-[36rem]:col-span-6 @min-[56rem]:col-span-6',
    full: 'col-span-full',
};

/**
 * An entry-row cell: the scale above plus the two cell kinds that are not
 * typed values (the quotation's group of checks, and a server figure).
 * Fixed widths are a flex basis, the rest a minimum; both apply only in the
 * one-line layout. Minimums: xs 96px, sm 144px (a date
 * input with its picker icon, a select), md 112px and lg 160px (both grow;
 * lg takes twice the share). EntryTableFitTest mirrors these numbers.
 */
export type EntryWidth = Exclude<FieldWidth, 'full'> | 'checks' | 'value';

export const ENTRY_WIDTH: Record<EntryWidth, string> = {
    'xs': 'flex-1 min-w-24', // 96px minimum
    'sm': 'flex-1 min-w-36', // 144px minimum
    'md': 'flex-1 min-w-28', // 112px minimum, grows
    'lg': 'flex-[2] min-w-40', // 160px minimum, twice the share of the rest
    'checks': 'flex-none basis-88', // 352px in the one-line layout only (no minimum, so the wrapped grid never overflows): Considered, Recommended and Ex Stock
    'value': 'flex-none basis-24 min-w-24', // 96px: a server figure (a dash until saved)
};
