<?php

namespace App\Submissions;

use App\Models\ProcurementCase;

/**
 * Result of SaveRequisition::handle (slice F2).
 *
 * - idMap: client_key => new row id, for the rows created in this save that
 *   carried a client_key, so the page can attach ids without reordering.
 * - rowIds: section => the saved rows' ids, in payload order (blank rows,
 *   which are dropped, are not listed). Only sections present in the payload.
 * - daysOnHand, expiryWarnings (slice V2-1): ExpiryRisk on the saved draft;
 *   warnings keyed by batch id. Advisory only.
 */
final readonly class SavedDraft
{
    /**
     * @param  array<string, int>  $idMap
     * @param  array<string, list<int>>  $rowIds
     * @param  array<int, array{state: string, days_until_expiry: int, cover_days: string}>  $expiryWarnings
     */
    public function __construct(
        public ProcurementCase $case,
        public array $idMap,
        public array $rowIds,
        public ?int $daysOnHand = null,
        public array $expiryWarnings = [],
        /** FORM-SAVE-1: a lenient (draft) save: key => message of what this save left out. */
        public array $dropped = [],
    ) {}
}
