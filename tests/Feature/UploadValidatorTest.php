<?php

use App\Documents\UploadRejected;
use App\Documents\UploadValidator;

// Slice 8c: UploadValidator. Type by content, extension allow-list, OOXML and
// zip safety, display-name sanitising. No database or disk writes.

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

it('accepts each allowed type detected by content', function (string $name, Closure $bytes, string $type, string $mime) {
    $file = inspectUpload($name, $bytes());

    expect($file->type)->toBe($type)
        ->and($file->mime)->toBe($mime);
})->with([
    'pdf' => ['quote.pdf', fn () => pdfBytes(), 'pdf', 'application/pdf'],
    'png' => ['scan.png', fn () => pngBytes(), 'png', 'image/png'],
    'jpg' => ['photo.jpg', fn () => jpegBytes(), 'jpeg', 'image/jpeg'],
    'jpeg' => ['photo.JPEG', fn () => jpegBytes(), 'jpeg', 'image/jpeg'],
    'docx' => ['letter.docx', fn () => zipBytes('docx'), 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xlsx' => ['prices.XLSX', fn () => zipBytes('xlsx'), 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
]);

it('rejects unsafe or mismatched files', function (string $name, Closure $bytes, string $reason) {
    expect(rejectionOf($name, $bytes()))->toBe($reason);
})->with([
    'executable named .pdf' => ['setup.pdf', fn () => "MZ\x90\x00\x03\x00\x00\x00This program cannot be run in DOS mode", 'unknown_type'],
    'pdf bytes named .png' => ['image.png', fn () => pdfBytes(), 'type_mismatch'],
    'docx bytes named .xlsx' => ['sheet.xlsx', fn () => zipBytes('docx'), 'type_mismatch'],
    'docm' => ['macro.docm', fn () => zipBytes('docx'), 'extension_not_allowed'],
    'xlsm' => ['macro.xlsm', fn () => zipBytes('xlsx'), 'extension_not_allowed'],
    'vbaProject.bin' => ['macro.docx', fn () => zipBytes('docx', ['word/vbaProject.bin' => 'macro']), 'macro_content'],
    'plain zip' => ['archive.docx', fn () => zipBytes('plain'), 'not_office_document'],
    'empty' => ['empty.pdf', fn () => '', 'empty'],
    'double extension' => ['a.pdf.exe', fn () => pdfBytes(), 'extension_not_allowed'],
    'no extension' => ['README', fn () => pdfBytes(), 'extension_not_allowed'],
    'null byte' => ["a\0.pdf", fn () => pdfBytes(), 'bad_name'],
    'control char' => ["a\x07b.pdf", fn () => pdfBytes(), 'bad_name'],
    'zip traversal' => ['evil.docx', fn () => zipBytes('docx', ['../evil.txt' => 'x']), 'unsafe_zip_entry'],
    'zip absolute path' => ['evil.docx', fn () => zipBytes('docx', ['/etc/passwd' => 'x']), 'unsafe_zip_entry'],
]);

it('rejects a file over the size limit', function () {
    config(['documents.max_size_kb' => 1]);

    expect(rejectionOf('big.pdf', pdfBytes().str_repeat('A', 2048)))->toBe('too_large')
        ->and(rejectionOf('small.pdf', pdfBytes()))->toBeNull();
});

it('rejects a zip with too many entries', function () {
    config(['documents.zip_max_entries' => 5]);

    $entries = [];
    foreach (range(1, 5) as $i) {
        $entries["word/media/image{$i}.txt"] = 'x';
    }

    expect(rejectionOf('many.docx', zipBytes('docx', $entries)))->toBe('zip_too_many_entries');
});

it('rejects a zip whose declared uncompressed size is over the cap', function () {
    config(['documents.zip_max_uncompressed_bytes' => 1000]);

    expect(rejectionOf('bomb.docx', zipBytes('docx', ['word/big.xml' => str_repeat('A', 5000)])))->toBe('zip_too_large');
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
