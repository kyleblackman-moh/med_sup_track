import { money, type QuotationPrice } from '@/lib/quotation-price';

/**
 * Kyle, 2026-11-25: a quotation's totals under its table, right-aligned like an
 * invoice: Subtotal, VAT, then Total Price Incl. VAT. A dash until the
 * quantity and unit price are both entered. Presentational only.
 */
export function QuotationTotals({ label, price }: { label: string; price: QuotationPrice | null }) {
    const figure = (amount: string | undefined) => (amount === undefined ? '—' : money(amount));

    return (
        <dl aria-label={`Totals, ${label}`} className="ms-auto grid w-full max-w-xs grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-sm">
            <dt className="text-base-content/70">Subtotal</dt>
            <dd className="text-end tabular-nums">{figure(price?.subtotal)}</dd>
            <dt className="text-base-content/70">VAT</dt>
            <dd className="text-end tabular-nums">{figure(price?.vat)}</dd>
            <dt className="border-base-300 border-t pt-1 font-semibold">Total Price Incl. VAT</dt>
            <dd className="border-base-300 border-t pt-1 text-end font-semibold tabular-nums">{figure(price?.total)}</dd>
        </dl>
    );
}
