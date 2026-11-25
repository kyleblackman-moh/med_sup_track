<?php

namespace App\Support;

/**
 * Kyle, 2026-11-23: a request form quotation row's computed money, never typed:
 *
 *   Subtotal    = Quantity x Unit Price, rounded half-up to 2 dp
 *   VAT         = Subtotal x VAT rate, rounded half-up to 2 dp
 *   Total Price = Subtotal + VAT (the two rounded parts, so a row always sums exactly)
 *
 * Exact decimal strings (bcmath, see Decimal); never floats. The VAT rate is
 * config('procurement.vat_rate'). The form computes the same figures live
 * (resources/js/lib/quotation-price.ts, kept in step by QuotationPriceTest).
 */
final class QuotationPrice
{
    /** @return array{subtotal: string, vat: string, total: string}|null null without both quantity and unit price */
    public static function compute(?string $quantity, ?string $unitPrice, ?string $vatRate = null): ?array
    {
        if ($quantity === null || $unitPrice === null || ! is_numeric($quantity) || ! is_numeric($unitPrice)) {
            return null;
        }

        $subtotal = Decimal::round2(Decimal::mul($quantity, $unitPrice));
        $vat = Decimal::round2(Decimal::mul($subtotal, $vatRate ?? self::vatRate()));

        return ['subtotal' => $subtotal, 'vat' => $vat, 'total' => Decimal::round2(Decimal::add($subtotal, $vat))];
    }

    /**
     * The recommended quotation's unit price against a reference price (tender or previous):
     * (quoted - reference) / reference x 100, half-up to 2 dp ("12.50", "-3.00"). Null without
     * both prices, or when the reference is zero. The form shows the same live
     * (priceChange in resources/js/lib/quotation-price.ts).
     */
    public static function changePercent(?string $quoted, ?string $reference): ?string
    {
        if ($quoted === null || $reference === null || ! is_numeric($quoted) || ! is_numeric($reference) || Decimal::compare($reference, '0') <= 0) {
            return null;
        }

        return Decimal::round2(Decimal::div(Decimal::mul(Decimal::sub($quoted, $reference), '100'), $reference));
    }

    /** The configured rate as a decimal string, for example "0.125". */
    public static function vatRate(): string
    {
        return (string) config('procurement.vat_rate');
    }
}
