/**
 * The Ministry of Health brand stripe (design.md section 2): a 4px decorative
 * bar across the top of the L1 canvas, four segments 6 / 2 / 1 / 1 in blue,
 * sky, lime and forest. Sky and lime are fills only. Hidden from assistive
 * technology and in print. One small component, so it can be removed in one place.
 */
export function BrandStripe() {
    return (
        <div aria-hidden="true" className="flex h-1 w-full shrink-0 print:hidden">
            <span className="bg-primary flex-[6]" />
            <span className="bg-brand-sky flex-[2]" />
            <span className="bg-brand-lime flex-1" />
            <span className="bg-brand-forest flex-1" />
        </div>
    );
}
