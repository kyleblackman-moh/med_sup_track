<?php

namespace App\Submissions;

/** What CaseEraser removed: ids and counts only. */
final readonly class ErasedCase
{
    /**
     * @param  array<string, int>  $rowCounts  child rows per table, by short name
     * @param  list<string>  $storageKeys  the files' keys on the documents disk
     */
    public function __construct(
        public string $caseId,
        public ?string $procurementTypeCode,
        public array $rowCounts,
        public int $fileCount,
        public array $storageKeys,
    ) {}
}
