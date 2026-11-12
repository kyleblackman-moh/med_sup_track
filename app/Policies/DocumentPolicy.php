<?php

namespace App\Policies;

use App\Enums\DocumentVisibility;
use App\Enums\Permission;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Per-document rules on top of case visibility. Never uses auth().
 *
 * - The document's case must be visible to the user (CasePolicy::view).
 * - Vendor users see only EXTERNAL documents.
 * - Withdrawn documents are neither listed nor downloadable.
 * - PENDING, INFECTED and ERROR documents are listed only to their uploader.
 * - Only CLEAN documents can be downloaded.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if ($document->isWithdrawn()) {
            return false;
        }

        if ($user->isVendor() && $document->visibility !== DocumentVisibility::EXTERNAL) {
            return false;
        }

        if ($document->scan_status !== ScanStatus::CLEAN && (int) $document->uploaded_by !== (int) $user->getKey()) {
            return false;
        }

        $case = ProcurementCase::withoutGlobalScopes()->find($document->case_id);

        return $case !== null && Gate::forUser($user)->allows('view', $case);
    }

    public function download(User $user, Document $document): bool
    {
        return $document->scan_status === ScanStatus::CLEAN
            && $user->can(Permission::DocumentDownload->value)
            && $this->view($user, $document);
    }
}
