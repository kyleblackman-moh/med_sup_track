<?php

namespace App\Support;

/**
 * IT-ADMIN: a safety net for the audit log viewer's row detail. Audit::record
 * already drops secret-looking keys when a row is written; this hides anything
 * that still looks sensitive when a row is shown, in rows written before that
 * rule or by mistake: keys naming a password, token, code, secret, recovery
 * code, signature or URL, and any string value that looks like a signed URL or
 * carries a token. The key stays visible with "[redacted]" so the reader knows
 * something was there.
 */
final class AuditRedactor
{
    public const MASK = '[redacted]';

    private const KEYS = '/pass|token|code|secret|recovery|signed|signature|url|otp|key/i';

    private const VALUES = '/(signature=|token=|expires=.*signature|^https?:\/\/\S*[?&](signature|token)=)/i';

    /** Keys that only look sensitive: plain ids and counts the viewer must show. */
    private const ALLOWED_KEYS = ['reason_id', 'reason_length', 'notification', 'status_code'];

    public static function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return is_string($data) && preg_match(self::VALUES, $data) ? self::MASK : $data;
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && ! in_array($key, self::ALLOWED_KEYS, true) && preg_match(self::KEYS, $key)) {
                $clean[$key] = self::MASK;

                continue;
            }

            $clean[$key] = self::redact($value);
        }

        return $clean;
    }
}
