<?php

use App\Documents\UploadRejected;
use App\Documents\UploadValidator;

// Slice 8c, UI-3: UploadValidator. PDF only (name, %PDF- signature and detected
// MIME), size limit, display-name sanitising. No database or disk writes.

function inspectUpload(string $name, string $bytes)
{
    return app(UploadValidator::class)->inspect(upload($name, $bytes));
}

function rejectionOf(string $name, string $bytes): ?string
{
    try {
        inspectUpload($name, $bytes);

        return null;
    } catch (UploadRejected $e) {
        return $e->reason;
    }
}

it('accepts a PDF detected by its bytes and MIME', function (string $name) {
    $file = inspectUpload($name, pdfBytes());

    expect($file->type)->toBe('pdf')
        ->and($file->mime)->toBe('application/pdf')
        ->and($file->extension)->toBe('pdf');
})->with(['quote.pdf', 'QUOTE.PDF']);

it('rejects every other type, whatever it is named, with one message', function (string $name, Closure $bytes, string $reason) {
    try {
        inspectUpload($name, $bytes());
        $this->fail('accepted');
    } catch (UploadRejected $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->getMessage())->toBe('Only PDF files are accepted.');
    }
})->with([
    // UI-3: types that were accepted before, under their own names.
    'docx' => ['letter.docx', fn () => zipBytes('docx'), 'extension_not_allowed'],
    'xlsx' => ['prices.xlsx', fn () => zipBytes('xlsx'), 'extension_not_allowed'],
    'jpg' => ['photo.jpg', fn () => jpegBytes(), 'extension_not_allowed'],
    'jpeg' => ['photo.jpeg', fn () => jpegBytes(), 'extension_not_allowed'],
    'png' => ['scan.png', fn () => pngBytes(), 'extension_not_allowed'],
    // ...and the same bytes renamed to .pdf.
    'docx named .pdf' => ['letter.pdf', fn () => zipBytes('docx'), 'unknown_type'],
    'xlsx named .pdf' => ['prices.pdf', fn () => zipBytes('xlsx'), 'unknown_type'],
    'jpg named .pdf' => ['photo.pdf', fn () => jpegBytes(), 'unknown_type'],
    'png named .pdf' => ['scan.pdf', fn () => pngBytes(), 'unknown_type'],
    'executable named .pdf' => ['setup.pdf', fn () => "MZ\x90\x00\x03\x00\x00\x00This program cannot be run in DOS mode", 'unknown_type'],
    'pdf bytes named .png' => ['image.png', fn () => pdfBytes(), 'extension_not_allowed'],
    'double extension' => ['a.pdf.exe', fn () => pdfBytes(), 'extension_not_allowed'],
    'no extension' => ['README', fn () => pdfBytes(), 'extension_not_allowed'],
]);

it('rejects empty files and bad names', function (string $name, Closure $bytes, string $reason) {
    expect(rejectionOf($name, $bytes()))->toBe($reason);
})->with([
    'empty' => ['empty.pdf', fn () => '', 'empty'],
    'null byte' => ["a\0.pdf", fn () => pdfBytes(), 'bad_name'],
    'control char' => ["a\x07b.pdf", fn () => pdfBytes(), 'bad_name'],
]);

it('rejects a file over the size limit', function () {
    config(['documents.max_size_kb' => 1]);

    expect(rejectionOf('big.pdf', pdfBytes().str_repeat('A', 2048)))->toBe('too_large')
        ->and(rejectionOf('small.pdf', pdfBytes()))->toBeNull();
});

it('keeps the size limit at 25 MB', function () {
    expect(config('documents.max_size_kb'))->toBe(25600)
        ->and(config('documents.allowed_extensions'))->toBe(['pdf']);
});

it('sanitises the display name and never keeps a path', function (string $name, string $expected) {
    expect(inspectUpload($name, pdfBytes())->displayName)->toBe($expected);
})->with([
    'traversal' => ['../../evil.pdf', 'evil.pdf'],
    'windows path' => ['C:\\Users\\me\\quote.pdf', 'quote.pdf'],
    'whitespace' => ['  my    quote    final.pdf', 'my quote final.pdf'], // a tab is a control character: rejected
    'trailing dots' => ['quote.pdf. . ', 'quote.pdf'],
    'only dots' => ['.pdf', 'document.pdf'],
]);

it('limits the display name to 150 characters', function () {
    $name = inspectUpload(str_repeat('a', 300).'.pdf', pdfBytes())->displayName;

    expect(mb_strlen($name))->toBe(150);
});

it('gives a safe message for each rejection', function () {
    try {
        inspectUpload('setup.pdf', 'MZ not a pdf');
        $this->fail('accepted');
    } catch (UploadRejected $e) {
        expect($e->getMessage())->toBeString()->not->toContain('MZ');
    }
});
