<?php

namespace App\Workflow\Actions;

use App\Models\ProcurementCase;
use Carbon\CarbonInterface;

/**
 * Outcome of a slice 10c step (prepare file, send to Legal, Legal decision):
 * done now, or already done earlier (double click, or another user), with who
 * did it, when, and a short description of what was done.
 */
final readonly class StepResult
{
    private function __construct(
        public ProcurementCase $case,
        public bool $already,
        public ?string $by = null,
        public ?CarbonInterface $at = null,
        public ?string $detail = null,
    ) {}

    public static function done(ProcurementCase $case): self
    {
        return new self($case, false);
    }

    public static function already(ProcurementCase $case, ?string $by, ?CarbonInterface $at, ?string $detail = null): self
    {
        return new self($case, true, $by, $at, $detail);
    }

    /** "<by> on <date>", in Port of Spain time, for flash messages. */
    public function byLine(): string
    {
        $when = $this->at?->copy()->setTimezone(RecordHardcopy::TIMEZONE)->format('j M Y, g:i a');

        return ($this->by ?? 'another user').($when ? " on {$when}" : '');
    }
}
