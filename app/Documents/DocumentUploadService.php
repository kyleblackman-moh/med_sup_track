<?php

namespace App\Documents;

use App\Documents\Scanning\ScanDocumentJob;
use App\Enums\AuditAction;
use App\Enums\DocumentPurpose;
use App\Enums\DocumentVisibility;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Accepts a vendor upload: authorise, inspect, store under a random key, record
 * the row and its audit, then queue the malware scan after commit.
 *
 * The client's name, extension and mime type are never trusted; the display
 * name is never used in a path. If anything fails after the bytes are written,
 * the stored file is removed (the only place a file is ever deleted).
 */
final class DocumentUploadService
{
    public function __construct(private readonly UploadValidator $validator) {}

    public function store(ProcurementCase $case, User $user, DocumentPurpose $purpose, UploadedFile $file, ?string $supersedesId = null): Document
    {
        DocumentAccess::authoriseChange($case, $user);

        $configured = ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
            ->where('purpose', $purpose->value)->exists();
        if (! $configured) {
            throw ValidationException::withMessages(['purpose' => 'This document is not part of this type of request.']);
        }

        $this->enforceCaseLimits($case, (int) $file->getSize());

        try {
            $inspected = $this->validator->inspect($file);
        } catch (UploadRejected $e) {
            Audit::record(
                AuditAction::DocumentValidationRejected,
                ['type' => 'case', 'id' => $case->getKey()],
                $case->getKey(),
                [
                    'outcome' => 'failed',
                    'reason' => $e->reason,
                    'declared_extension' => mb_substr(UploadValidator::lastExtension(UploadValidator::displayName((string) $file->getClientOriginalName())), 0, 20),
                    'size_bytes' => (int) $file->getSize(),
                ],
                $user,
            );

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $disk = Storage::disk('documents');
        $id = Str::lower((string) Str::ulid());
        $key = "cases/{$case->getKey()}/{$id}";

        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $disk->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            $sha256 = $this->hashStored($key);
            $size = $disk->size($key);

            return DB::transaction(function () use ($case, $user, $purpose, $supersedesId, $inspected, $id, $key, $sha256, $size) {
                $version = 1;

                if ($supersedesId !== null) {
                    $previous = Document::withoutGlobalScopes()->current()
                        ->whereKey($supersedesId)
                        ->where('case_id', $case->getKey())
                        ->where('purpose', $purpose->value)
                        ->lockForUpdate()
                        ->first();

                    if ($previous === null) {
                        throw ValidationException::withMessages(['supersedes_id' => 'Only a current document of the same kind can be replaced.']);
                    }

                    $version = $previous->version + 1;
                }

                $document = new Document;
                $document->forceFill([
                    'id' => $id,
                    'case_id' => $case->getKey(),
                    'purpose' => $purpose,
                    'version' => $version,
                    'supersedes_id' => $supersedesId,
                    'original_name' => $inspected->displayName,
                    'storage_key' => $key,
                    'mime' => $inspected->mime,
                    'size_bytes' => $size,
                    'sha256' => $sha256,
                    'scan_status' => ScanStatus::PENDING,
                    'visibility' => DocumentVisibility::EXTERNAL, // vendor uploads only in this slice
                    'uploaded_by' => $user->getKey(),
                    'uploaded_at' => CarbonImmutable::now('UTC'),
                ])->save();

                Audit::record(
                    AuditAction::DocumentUploadReceived,
                    ['type' => 'document', 'id' => $id],
                    $case->getKey(),
                    [
                        'document_id' => $id,
                        'purpose' => $purpose->value,
                        'version' => $version,
                        'size_bytes' => $size,
                        'sha256' => $sha256,
                        'display_name' => $inspected->displayName,
                    ],
                    $user,
                );

                if ($supersedesId !== null) {
                    Audit::record(
                        AuditAction::DocumentSuperseded,
                        ['type' => 'document', 'id' => $supersedesId],
                        $case->getKey(),
                        ['document_id' => $id, 'supersedes_id' => $supersedesId, 'version' => $version],
                        $user,
                    );
                }

                DB::afterCommit(fn () => ScanDocumentJob::dispatch($id));

                return $document;
            });
        } catch (Throwable $e) {
            $disk->delete($key); // compensating cleanup: nothing was recorded

            throw $e;
        }
    }

    private function enforceCaseLimits(ProcurementCase $case, int $incomingBytes): void
    {
        $existing = Document::withoutGlobalScopes()->where('case_id', $case->getKey());

        if ((clone $existing)->count() >= (int) config('documents.max_files_per_case')) {
            throw ValidationException::withMessages(['file' => 'This request already has the maximum number of documents.']);
        }

        if ((int) (clone $existing)->sum('size_bytes') + $incomingBytes > (int) config('documents.max_bytes_per_case')) {
            throw ValidationException::withMessages(['file' => 'This request has reached its total storage limit.']);
        }
    }

    private function hashStored(string $key): string
    {
        $stream = Storage::disk('documents')->readStream($key);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }
}
