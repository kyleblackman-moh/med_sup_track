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

/** Withdraws a document from a draft. The row and the file are kept. */
final class WithdrawDocument
{
    public function handle(Document $document, User $user, string $reason): Document
    {
        $case = ProcurementCase::withoutGlobalScopes()->findOrFail($document->case_id);
        DocumentAccess::authoriseChange($case, $user);

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of up to 500 characters.']);
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
