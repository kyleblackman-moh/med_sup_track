<?php

namespace App\Http\Resources\Requests;

use App\Documents\DocumentAccess;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The "documents" prop: one entry per purpose configured for the case's type,
 * listing the CURRENT documents DocumentPolicy lets this user see.
 *
 * Built field by field. Never includes storage_key, sha256, mime, scan_status,
 * scan_detail (signature names), scan_attempts, uploaded_by or withdrawn_*.
 */
final class RequestDocuments
{
    public static function for(ProcurementCase $case, User $user): array
    {
        $gate = Gate::forUser($user);
        $canChange = DocumentAccess::canChange($case, $user);

        $documents = Document::withoutGlobalScopes()->current()
            ->where('case_id', $case->getKey())
            ->orderBy('uploaded_at')->orderBy('id')
            ->get()
            ->filter(fn (Document $d) => $gate->allows('view', $d))
            ->groupBy(fn (Document $d) => $d->purpose->value);

        return ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
            ->orderBy('sort_order')->get()
            ->map(fn (ProcurementTypeDocument $requirement) => [
                'purpose' => $requirement->purpose->value,
                'label' => $requirement->label,
                'is_required' => $requirement->is_required,
                'documents' => $documents->get($requirement->purpose->value, collect())
                    ->map(fn (Document $d) => [
                        'id' => $d->id,
                        'display_name' => $d->original_name,
                        'size_bytes' => $d->size_bytes,
                        'uploaded_at' => $d->uploaded_at?->copy()->utc()->toIso8601ZuluString(),
                        'version' => $d->version,
                        'status_label' => $d->scan_status->label(),
                        'can_download' => $gate->allows('download', $d),
                        'can_replace' => $canChange,
                        'can_withdraw' => $canChange,
                    ])->values()->all(),
            ])->all();
    }

    /** Helper text for the upload form; the server enforces these. */
    public static function uploadRules(): array
    {
        return [
            'extensions' => config('documents.allowed_extensions'),
            'max_size_kb' => (int) config('documents.max_size_kb'),
        ];
    }
}
