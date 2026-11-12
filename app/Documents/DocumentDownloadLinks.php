<?php

namespace App\Documents;

use App\Enums\AuditAction;
use App\Models\Document;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Issues a short-lived signed link to one CLEAN document for one user. The
 * download is audited before the link is returned (fail closed).
 */
final class DocumentDownloadLinks
{
    /** @throws AuthorizationException */
    public function issue(Document $document, User $user): string
    {
        Gate::forUser($user)->authorize('download', $document);

        Audit::record(
            AuditAction::DocumentDownloaded,
            ['type' => 'document', 'id' => $document->getKey()],
            $document->case_id,
            ['document_id' => $document->getKey(), 'purpose' => $document->purpose->value, 'version' => $document->version],
            $user,
        );

        return URL::temporarySignedRoute(
            'documents.file',
            now()->addSeconds((int) config('documents.download_link_ttl_seconds')),
            ['document' => $document->getKey(), 'user' => $user->getKey()],
        );
    }
}
