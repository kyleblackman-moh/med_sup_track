<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Http\Middleware\AssignRequestId;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Append-only audit log writer (spec 13.4).
 *
 * - Synchronous, one INSERT on the default connection, so the row joins the
 *   caller's transaction: if the business action rolls back, so does its audit row.
 * - Fails closed: record() throws if the row cannot be written.
 * - Never UPDATE or DELETE audit_logs (a database trigger rejects it anyway).
 */
final class Audit
{
    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_ANONYMOUS = 'anonymous';

    /** Metadata keys removed (at any depth) before saving. */
    private const REDACT_KEYS = '/pass|token|secret|code|otp|key/i';

    private const USER_AGENT_MAX = 255;

    private const EMAIL_MAX = 120;

    /**
     * @param  array{type: string, id: string|int}|null  $subject
     * @param  string|null  $caseId  Case ULID.
     * @param  User|string|null  $actor  null = the authenticated user, or anonymous if none.
     *                                   A User = explicit actor (e.g. before the session exists).
     *                                   Audit::ACTOR_SYSTEM for console, seeder or scheduled work.
     */
    public static function record(
        AuditAction|string $action,
        ?array $subject = null,
        ?string $caseId = null,
        array $metadata = [],
        User|string|null $actor = null,
    ): void {
        [$actorType, $actorUser] = self::resolveActor($actor);
        $request = self::currentRequest();
        $metadata = self::redact($metadata);

        DB::table('audit_logs')->insert([
            'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
            'actor_user_id' => $actorUser?->getKey(),
            'actor_org_id' => $actorUser?->org_id,
            'actor_type' => $actorType,
            'action' => $action instanceof AuditAction ? $action->value : $action,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => isset($subject['id']) ? (string) $subject['id'] : null,
            'case_id' => $caseId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() !== null
                ? mb_substr($request->userAgent(), 0, self::USER_AGENT_MAX)
                : null,
            'request_id' => AssignRequestId::of($request), // IT-ADMIN
            'metadata' => $metadata === []
                ? null
                : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * BEST-EFFORT variant, for AUTHENTICATION EVENTS ONLY.
     *
     * Login, logout and lockout events are recorded before or around the session
     * and outside any business transaction. If the audit table were unavailable,
     * a fail-closed write here would stop everyone from logging in, including the
     * people who need to fix it. So these calls log a critical error and carry on.
     *
     * Business actions (cases, decisions, letters...) must call record() directly
     * and fail closed. Do not use this method for them.
     */
    public static function recordAuthEvent(
        AuditAction $action,
        array $metadata = [],
        User|string|null $actor = null,
        ?array $subject = null,
    ): void {
        self::recordBestEffort($action, $subject, $metadata, $actor);
    }

    /**
     * IT-ADMIN: best-effort, for SECURITY SIGNALS only (access.denied,
     * log.viewed, mail.send_failed): rows written while a request is being
     * refused, or while something else has already failed. A failed write
     * must not turn a 403 or 404 into a 500, so it logs and carries on.
     * Business actions call record() and fail closed.
     */
    public static function recordBestEffort(
        AuditAction $action,
        ?array $subject = null,
        array $metadata = [],
        User|string|null $actor = null,
    ): void {
        try {
            self::record($action, $subject, null, $metadata, $actor);
        } catch (Throwable $e) {
            Log::critical('audit write failed', [
                'action' => $action->value,
                'exception_class' => get_class($e),
            ]);
        }
    }

    /**
     * What may be stored for a value typed into an email field: the lowercased,
     * truncated address if it is a valid email, otherwise "[invalid]" (people
     * sometimes type their password into the email field).
     */
    public static function safeEmail(mixed $input): string
    {
        $email = is_string($input) ? mb_strtolower(trim($input)) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '[invalid]';
        }

        return mb_substr($email, 0, self::EMAIL_MAX);
    }

    /** Recursively drop keys that look like secrets. */
    public static function redact(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::REDACT_KEYS, $key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }

    /** @return array{0: string, 1: User|null} */
    private static function resolveActor(User|string|null $actor): array
    {
        if ($actor instanceof User) {
            return ['user', $actor];
        }

        if ($actor === self::ACTOR_SYSTEM || $actor === self::ACTOR_ANONYMOUS) {
            return [$actor, null];
        }

        if ($actor !== null) {
            throw new InvalidArgumentException("Unknown audit actor [{$actor}].");
        }

        $user = Auth::user();

        return $user instanceof User ? ['user', $user] : [self::ACTOR_ANONYMOUS, null];
    }

    private static function currentRequest(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
