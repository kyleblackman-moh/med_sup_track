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
 */
final readonly class SavedDraft
{
    /**
     * @param  array<string, int>  $idMap
     * @param  array<string, list<int>>  $rowIds
     */
    public function __construct(
        public ProcurementCase $case,
        public array $idMap,
        public array $rowIds,
    ) {}
}
