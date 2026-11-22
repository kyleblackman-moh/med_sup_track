<?php

namespace App\Http\Resources\Admin;

use App\Models\AuditLog;
use App\Support\AuditCatalogue;
use App\Support\AuditRedactor;

/**
 * IT-ADMIN: what the audit log viewer sends to React. Plain arrays; times in
 * America/Port_of_Spain; outcome label and tone from the server. A list row
 * carries no metadata; the detail (one row, on request) carries it redacted.
 * Expects AuditLogController's joins (actor_name, actor_email, case_reference).
 */
final class AuditLogView
{
    public const TIMEZONE = 'America/Port_of_Spain';

    /** @return array<string, mixed> */
    public static function row(AuditLog $row): array
    {
        $denied = AuditCatalogue::isDenied($row->action);

        return [
            'id' => (int) $row->id,
            'occurred_at' => $row->occurred_at->setTimezone(self::TIMEZONE)->format('j M Y, g:i:s a'),
            'actor' => self::actor($row),
            'action' => $row->action,
            'category' => AuditCatalogue::categoryLabel($row->action),
            'outcome_label' => $denied ? 'Denied' : 'Allowed',
            'outcome_tone' => $denied ? 'error' : 'success',
            'subject' => $row->subject_type === null ? null : $row->subject_type.' #'.$row->subject_id,
            'case_reference' => $row->case_reference ?? ($row->case_id === null ? null : 'Draft (no reference)'),
            'ip_address' => $row->ip_address,
            'request_id' => $row->request_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(AuditLog $row): array
    {
        $metadata = $row->metadata === null ? [] : json_decode($row->metadata, true);

        return [
            ...self::row($row),
            'actor_type' => $row->actor_type,
            'user_agent' => $row->user_agent,
            'metadata' => AuditRedactor::redact(is_array($metadata) ? $metadata : []),
        ];
    }

    private static function actor(AuditLog $row): string
    {
        return match ($row->actor_type) {
            'system' => 'System',
            'anonymous' => 'Anonymous',
            default => $row->actor_name !== null
                ? $row->actor_name.' ('.$row->actor_email.')'
                : 'User #'.$row->actor_user_id,
        };
    }
}
