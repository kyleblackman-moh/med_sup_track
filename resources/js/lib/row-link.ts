/**
 * Whole-row click without onClick: the row's primary link is stretched over
 * the row with an ::after overlay. The row needs `relative`; any other control
 * in the row needs `relative z-10` to sit above the overlay. The focus ring is
 * drawn on the overlay, so the whole row shows it.
 */
export const rowLink =
    'outline-none after:absolute after:inset-0 focus-visible:after:ring-2 focus-visible:after:ring-ring focus-visible:after:ring-inset';
