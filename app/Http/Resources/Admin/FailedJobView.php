<?php

namespace App\Http\Resources\Admin;

use Carbon\CarbonImmutable;

/**
 * IT-ADMIN Part 3: one failed_jobs row for the IT health page and the failed
 * jobs list. The job's class name (payload displayName) and the exception's
 * class only: never the serialised command, the payload or the exception
 * message, which can hold case data or addresses.
 */
final class FailedJobView
{
    public const TIMEZONE = 'America/Port_of_Spain';

    /** @return array<string, mixed> */
    public static function row(object $job): array
    {
        $payload = json_decode((string) $job->payload, true);
        $firstLine = strtok((string) $job->exception, "\n") ?: '';
        $exception = trim(explode(':', $firstLine, 2)[0]);

        return [
            'uuid' => (string) $job->uuid,
            'job' => is_array($payload) ? (string) ($payload['displayName'] ?? 'Unknown job') : 'Unknown job',
            'connection' => (string) $job->connection,
            'queue' => (string) $job->queue,
            // A class name looks like Foo\BarException; anything else is not shown.
            'exception' => preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $exception) ? $exception : 'Exception',
            'failed_at' => CarbonImmutable::parse((string) $job->failed_at, 'UTC')->setTimezone(self::TIMEZONE)->format('j M Y, g:i a'),
        ];
    }
}
