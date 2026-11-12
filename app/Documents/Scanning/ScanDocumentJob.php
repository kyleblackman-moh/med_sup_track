<?php

namespace App\Documents\Scanning;

use App\Enums\AuditAction;
use App\Enums\ScanStatus;
use App\Events\DocumentQuarantined;
use App\Models\Document;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Scans one uploaded document. Idempotent: only PENDING documents are scanned.
 * A scanner error throws so the job retries; after the final attempt failed()
 * marks the document ERROR. Nothing here ever ends in CLEAN without a clean verdict.
 */
class ScanDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> seconds before retries 2 and 3 */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $documentId) {}

    public function handle(Scanner $scanner): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);

        if ($document === null || $document->scan_status !== ScanStatus::PENDING) {
            return;
        }

        $document->forceFill(['scan_attempts' => $document->scan_attempts + 1])->save();

        $result = $scanner->scan(Storage::disk('documents')->path($document->storage_key));

        match ($result->state) {
            ScanResult::CLEAN => $this->release($document),
            ScanResult::INFECTED => $this->quarantine($document, (string) $result->detail),
            default => throw new ScanFailed((string) $result->detail),
        };
    }

    public function failed(?Throwable $exception): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);

        if ($document === null || $document->scan_status !== ScanStatus::PENDING) {
            return;
        }

        $detail = self::sanitise($exception instanceof ScanFailed ? $exception->getMessage() : 'Scanner failed.');

        DB::transaction(function () use ($document, $detail) {
            $document->forceFill([
                'scan_status' => ScanStatus::ERROR,
                'scan_detail' => $detail,
                'scanned_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $this->audit($document, AuditAction::DocumentScanError, ['outcome' => 'failed', 'attempts' => $document->scan_attempts]);
        });
    }

    private function release(Document $document): void
    {
        DB::transaction(function () use ($document) {
            $now = CarbonImmutable::now('UTC');
            $document->forceFill(['scan_status' => ScanStatus::CLEAN, 'scanned_at' => $now, 'released_at' => $now])->save();

            $this->audit($document, AuditAction::DocumentScanClean, ['outcome' => 'clean']);
            $this->audit($document, AuditAction::DocumentReleased, []);
        });
    }

    private function quarantine(Document $document, string $signature): void
    {
        $disk = Storage::disk('documents');
        $from = $document->storage_key;
        $to = 'quarantine/'.$from;

        $disk->move($from, $to);

        try {
            DB::transaction(function () use ($document, $signature, $to) {
                $document->forceFill([
                    'scan_status' => ScanStatus::INFECTED,
                    'scan_detail' => self::sanitise($signature),
                    'scanned_at' => CarbonImmutable::now('UTC'),
                    'storage_key' => $to,
                ])->save();

                $this->audit($document, AuditAction::DocumentScanInfected, ['outcome' => 'failed', 'signature' => self::sanitise($signature)]);
            });
        } catch (Throwable $e) {
            $disk->move($to, $from); // keep the row and the file in step

            throw $e;
        }

        DocumentQuarantined::dispatch($document->getKey());
    }

    private function audit(Document $document, AuditAction $action, array $metadata): void
    {
        Audit::record(
            $action,
            ['type' => 'document', 'id' => $document->getKey()],
            $document->case_id,
            ['document_id' => $document->getKey(), ...$metadata],
            Audit::ACTOR_SYSTEM,
        );
    }

    private static function sanitise(string $text): string
    {
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '');

        return mb_substr($clean === '' ? 'Scanner failed.' : $clean, 0, 255);
    }
}
