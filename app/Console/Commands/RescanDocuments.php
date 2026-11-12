<?php

namespace App\Console\Commands;

use App\Documents\Scanning\ScanDocumentJob;
use App\Enums\AuditAction;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Re-queues scans that never finished (PENDING) or ended in ERROR. */
class RescanDocuments extends Command
{
    protected $signature = 'documents:rescan {--minutes=15 : Only documents uploaded more than this many minutes ago}';

    protected $description = 'Re-queue malware scans for PENDING or ERROR documents';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $count = 0;

        Document::withoutGlobalScopes()
            ->whereIn('scan_status', [ScanStatus::PENDING->value, ScanStatus::ERROR->value])
            ->where('uploaded_at', '<', $cutoff)
            ->orderBy('uploaded_at')
            ->each(function (Document $document) use (&$count) {
                DB::transaction(function () use ($document) {
                    // The job scans PENDING documents only.
                    $document->forceFill(['scan_status' => ScanStatus::PENDING, 'scan_detail' => null])->save();

                    Audit::record(
                        AuditAction::DocumentRescanQueued,
                        ['type' => 'document', 'id' => $document->getKey()],
                        $document->case_id,
                        ['document_id' => $document->getKey()],
                        Audit::ACTOR_SYSTEM,
                    );

                    DB::afterCommit(fn () => ScanDocumentJob::dispatch($document->getKey()));
                });
                $count++;
            });

        $this->info("Re-queued {$count} document(s).");

        return self::SUCCESS;
    }
}
