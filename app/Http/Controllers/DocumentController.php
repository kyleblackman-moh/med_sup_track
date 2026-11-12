<?php

namespace App\Http\Controllers;

use App\Documents\DocumentDownloadLinks;
use App\Documents\DocumentUploadService;
use App\Documents\Exceptions\UploadNotAllowed;
use App\Documents\WithdrawDocument;
use App\Enums\DocumentPurpose;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Models\ProcurementCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vendor document actions. Thin: rules live in App\Documents. Hidden cases and
 * documents are 404 (scoped route model binding); a document that is not for
 * this user is 404 too. Only the signed file route answers 403, for a bad
 * signature or the wrong user, because the case was already authorised.
 */
class DocumentController extends Controller
{
    public function store(Request $request, ProcurementCase $procurementCase, DocumentUploadService $uploads): RedirectResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', 'string', 'max:64'],
            'file' => ['required', 'file'],
            'supersedes_id' => ['nullable', 'string', 'max:26'],
        ]);

        $purpose = DocumentPurpose::tryFrom($validated['purpose'])
            ?? throw ValidationException::withMessages(['purpose' => 'Choose a valid document type.']);

        try {
            $uploads->store($procurementCase, $request->user(), $purpose, $request->file('file'), $validated['supersedes_id'] ?? null);
        } catch (UploadNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Document uploaded. It will be checked for viruses before it can be used.');
    }

    public function withdraw(Request $request, ProcurementCase $procurementCase, Document $document, WithdrawDocument $withdraw): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $withdraw->handle($document, $request->user(), $validated['reason']);
        } catch (UploadNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Document removed.');
    }

    public function download(Request $request, Document $document, DocumentDownloadLinks $links): RedirectResponse
    {
        abort_unless(Gate::allows('download', $document), 404);

        return redirect()->away($links->issue($document, $request->user()));
    }

    public function file(Request $request, Document $document): StreamedResponse
    {
        // The link was issued to one user; the signature covers this value.
        abort_unless((string) $request->query('user') === (string) $request->user()->getKey(), 403);

        $document = Document::withoutGlobalScopes()->findOrFail($document->getKey()); // re-read: state may have changed
        abort_unless(
            $document->scan_status === ScanStatus::CLEAN && ! $document->isWithdrawn() && Gate::allows('download', $document),
            404,
        );

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $document->original_name,
            preg_replace('/[^\x20-\x7E]/', '_', $document->original_name) ?: 'document',
        );

        $response = Storage::disk('documents')->response($document->storage_key, null, [
            'Content-Type' => $document->mime, // detected from content at upload, never from the name
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
