<?php

use App\Http\Middleware\ConfirmRecentPassword;
use App\Models\AdminActionReason;
use Illuminate\Support\Facades\DB;

/**
 * IT-ADMIN: admin actions need a recently confirmed password
 * (ConfirmRecentPassword). Marks the test session as just confirmed; the
 * array session store keeps it for every later request of the test.
 */
function confirmAdminPassword(): void
{
    test()->withSession([ConfirmRecentPassword::SESSION_KEY => now()->getTimestamp()]);
}

/** The reason text stored for an audit row's metadata (reason_id), or null. */
function reasonTextFor(?array $metadata): ?string
{
    $id = $metadata['reason_id'] ?? null;

    return $id === null ? null : AdminActionReason::query()->whereKey($id)->value('reason');
}

/** The newest audit row for an action and subject user, decoded metadata included. */
function latestAuditFor(string $action, int $userId): ?object
{
    $row = DB::table('audit_logs')
        ->where('action', $action)
        ->where('subject_type', 'user')
        ->where('subject_id', (string) $userId)
        ->orderByDesc('id')
        ->first();

    if ($row !== null) {
        $row->meta = $row->metadata === null ? [] : json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);
    }

    return $row;
}
