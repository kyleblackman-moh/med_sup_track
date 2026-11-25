<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * The qpdf command line (config procurement.letter.qpdf, env QPDF_PATH). It
 * appends uploaded PDFs to a generated one; dompdf cannot read a PDF. Every
 * call is best effort: a file qpdf cannot open, or a missing binary, is
 * reported as null / false and the caller carries on without that file.
 */
final class Qpdf
{
    private const TIMEOUT_SECONDS = 120;

    public static function binary(): string
    {
        return (string) config('procurement.letter.qpdf', 'qpdf');
    }

    /** Page count, or null when qpdf is missing or cannot open the file (damaged, password-protected). */
    public static function pages(string $path): ?int
    {
        $output = self::run(['--warning-exit-0', '--show-npages', $path]);

        return $output === null || ! ctype_digit(trim($output)) ? null : (int) trim($output);
    }

    /**
     * $pdf bytes followed by the pages of every $file, in order. With $stamp (a
     * one-page PDF) each appended page is overlaid with it. Null when qpdf is
     * missing or the merge fails; the caller falls back to $pdf alone.
     *
     * @param  list<string>  $files  absolute paths
     */
    public static function append(string $pdf, array $files, ?string $stamp = null): ?string
    {
        if ($files === []) {
            return $pdf;
        }

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qpdf-'.Str::lower((string) Str::ulid());
        File::ensureDirectoryExists($dir);

        try {
            $letter = "{$dir}/letter.pdf";
            $appended = "{$dir}/appended.pdf";
            $merged = "{$dir}/merged.pdf";
            file_put_contents($letter, $pdf);

            $stampArguments = [];
            if ($stamp !== null) {
                file_put_contents("{$dir}/stamp.pdf", $stamp);
                $stampArguments = ['--overlay', "{$dir}/stamp.pdf", '--repeat=1', '--'];
            }

            // Two passes: the stamp goes on the appended pages only (the letter carries its own).
            if (self::run(['--warning-exit-0', '--empty', $appended, '--pages', ...$files, '--', ...$stampArguments]) === null
                || self::run(['--warning-exit-0', '--empty', $merged, '--pages', $letter, $appended, '--']) === null) {
                return null;
            }

            $bytes = file_get_contents($merged);

            return $bytes === false ? null : $bytes;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** @param  list<string>  $arguments */
    private static function run(array $arguments): ?string
    {
        try {
            $process = new Process([self::binary(), ...$arguments], null, null, null, self::TIMEOUT_SECONDS);
            $process->run();
        } catch (ProcessException $e) {
            Log::warning('qpdf could not run', ['binary' => self::binary(), 'error' => $e->getMessage()]);

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::info('qpdf failed', ['exit' => $process->getExitCode(), 'stderr' => Str::limit($process->getErrorOutput(), 500)]);

            return null;
        }

        return $process->getOutput();
    }
}
