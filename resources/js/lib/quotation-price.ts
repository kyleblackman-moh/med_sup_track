/**
 * A quotation row's computed money, live as the vendor types (Kyle, 2026-11-23).
 * The same rules as the server (app/Support/QuotationPrice.php), checked against
 * it by tests/Feature/QuotationPriceTest.php:
 *
 *   Subtotal    = Quantity x Unit Price, rounded half-up to 2 dp
 *   VAT         = Subtotal x VAT rate, rounded half-up to 2 dp
 *   Total Price = Subtotal + VAT
 *
 * Exact integer arithmetic (BigInt), never floats. The VAT rate comes from the
 * server (config procurement.vat_rate), never from here. No imports, so the
 * test can run this file with Node directly.
 */

export type QuotationPrice = { subtotal: string; vat: string; total: string };

type Scaled = { units: bigint; scale: number };

/** "12.50" as 1250 at scale 2; null unless a plain non-negative decimal. */
function parse(value: string | null | undefined): Scaled | null {
    const match = /^(\d+)(?:\.(\d*))?$/.exec((value ?? '').trim());
    if (match === null) {
        return null;
    }
    const fraction = match[2] ?? '';

    return { units: BigInt(match[1] + fraction), scale: fraction.length };
}

/** Half-up to 2 decimal places, as whole cents (values are never negative). */
function toCents({ units, scale }: Scaled): bigint {
    if (scale <= 2) {
        return units * 10n ** BigInt(2 - scale);
    }
    const divisor = 10n ** BigInt(scale - 2);
    const cents = units / divisor;

    return (units % divisor) * 2n >= divisor ? cents + 1n : cents;
}

function format(cents: bigint): string {
    const text = cents.toString().padStart(3, '0');

    return `${text.slice(0, -2)}.${text.slice(-2)}`;
}

/** Null until both quantity and unit price are numbers (and the rate is valid). */
export function quotationPrice(quantity: string | null | undefined, unitPrice: string | null | undefined, vatRate: string): QuotationPrice | null {
    const qty = parse(quantity);
    const price = parse(unitPrice);
    const rate = parse(vatRate);
    if (qty === null || price === null || rate === null) {
        return null;
    }

    const subtotal = toCents({ units: qty.units * price.units, scale: qty.scale + price.scale });
    const vat = toCents({ units: subtotal * rate.units, scale: 2 + rate.scale });

    return { subtotal: format(subtotal), vat: format(vat), total: format(subtotal + vat) };
}

/**
 * The recommended quotation's unit price against a reference price (tender or previous),
 * live as the vendor types (Kyle, 2026-11-25): (quoted - reference) / reference x 100,
 * half-up (away from zero) to 2 dp, as the server's QuotationPrice::changePercent
 * ("12.50", "-3.00"). Null without both prices, or when the reference is zero.
 */
export function priceChange(quoted: string | null | undefined, reference: string | null | undefined): string | null {
    const q = parse(quoted);
    const r = parse(reference);
    if (q === null || r === null || r.units === 0n) {
        return null;
    }

    // Both at the same scale, so the scales cancel in the ratio.
    const scale = Math.max(q.scale, r.scale);
    const qUnits = q.units * 10n ** BigInt(scale - q.scale);
    const rUnits = r.units * 10n ** BigInt(scale - r.scale);
    // Hundredths of a percent: (q - r) x 100 x 100 / r.
    const numerator = (qUnits - rUnits) * 10000n;
    const negative = numerator < 0n;
    const magnitude = negative ? -numerator : numerator;
    let hundredths = magnitude / rUnits;
    if ((magnitude % rUnits) * 2n >= rUnits) {
        hundredths += 1n;
    }

    return `${negative && hundredths > 0n ? '-' : ''}${format(hundredths)}`;
}

/** "1234.5" to "$1,234.5": the dollar sign and thousands commas on an amount as given. */
export function money(amount: string): string {
    const [whole, fraction] = amount.split('.');

    return `$${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${fraction === undefined ? '' : `.${fraction}`}`;
}
