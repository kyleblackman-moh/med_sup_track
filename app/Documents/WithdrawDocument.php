<?php

namespace App\Documents;

use App\Enums\AuditAction;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws (removes) a document from a draft. The row and the file are kept.
 * The reason is optional (slice F1: the drop zones send none); when given it is
 * stored on the row only, never in the audit payload.
 */
final class WithdrawDocument
{
    public function handle(Document $document, User $user, ?string $reason = null): Document
    {
        $case = ProcurementCase::withoutGlobalScopes()->findOrFail($document->case_id);
        DocumentAccess::authoriseChange($case, $user);

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Keep the reason to 500 characters or fewer.']);
        }

        return DB::transaction(function () use ($document, $user, $reason) {
            $locked = Document::withoutGlobalScopes()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isWithdrawn()) {
                throw ValidationException::withMessages(['document' => 'This document has already been removed.']);
            }

            $locked->forceFill([
                'withdrawn_at' => CarbonImmutable::now('UTC'),
                'withdrawn_by' => $user->getKey(),
                'withdrawn_reason' => $reason,
            ])->save();

            Audit::record(
                AuditAction::DocumentWithdrawn,
                ['type' => 'document', 'id' => $locked->getKey()],
                $locked->case_id,
                ['document_id' => $locked->getKey(), 'purpose' => $locked->purpose->value, 'version' => $locked->version],
                $user,
            );

            return $locked;
        });
    }
}
