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

        $configured = ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
            ->orderBy('sort_order')->get();

        $row = fn (Document $d, bool $editable) => [
            'id' => $d->id,
            'display_name' => $d->original_name,
            'size_bytes' => $d->size_bytes,
            'uploaded_at' => $d->uploaded_at?->copy()->utc()->toIso8601ZuluString(),
            'version' => $d->version,
            'status_label' => $d->scan_status->label(),
            'can_download' => $gate->allows('download', $d),
            'can_replace' => $editable,
            'can_withdraw' => $editable,
        ];

        $groups = $configured->map(fn (ProcurementTypeDocument $requirement) => [
            'purpose' => $requirement->purpose->value,
            'label' => $requirement->label,
            'hint' => $requirement->hint,
            'is_required' => $requirement->is_required,
            'read_only' => false,
            'documents' => $documents->get($requirement->purpose->value, collect())
                ->map(fn (Document $d) => $row($d, $canChange))->values()->all(),
        ])->all();

        // Slice F1: current documents in a category the type no longer uses (a
        // draft moved from AD_HOC_PHARM to AD_HOC). Readable, never editable,
        // and not counted by readiness.
        $purposes = $configured->map(fn (ProcurementTypeDocument $r) => $r->purpose->value)->all();
        $earlier = $documents->toBase()
            ->reject(fn ($docs, string $purpose) => in_array($purpose, $purposes, true))
            ->flatten(1)
            ->sortBy(fn (Document $d) => [$d->uploaded_at, $d->id])->values();

        if ($earlier->isNotEmpty()) {
            $groups[] = [
                'purpose' => self::EARLIER,
                'label' => 'Earlier Uploads',
                'hint' => null,
                'is_required' => false,
                'read_only' => true,
                'documents' => $earlier->map(fn (Document $d) => $row($d, false))->all(),
            ];
        }

        return $groups;
    }

    /** Group key for documents outside the type's categories. Not a DocumentPurpose. */
    public const EARLIER = 'earlier';

    /** Helper text for the upload form; the server enforces these. */
    public static function uploadRules(): array
    {
        return [
            'extensions' => config('documents.allowed_extensions'),
            'max_size_kb' => (int) config('documents.max_size_kb'),
        ];
    }
}
