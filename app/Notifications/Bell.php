<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * NOTIF-2: the in-app bell. Reads and marks the signed-in user's own rows of
 * the notifications table; never anyone else's. A row is a pointer: what it
 * links to authorises itself when opened.
 */
final class Bell
{
    public const LATEST = 10;

    public static function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * Opening a case clears its notifications from the count: every unread row
     * of $user for $caseId is marked read.
     */
    public static function markCaseRead(User $user, string $caseId): void
    {
        $user->unreadNotifications()->where('case_id', $caseId)->update(['read_at' => CarbonImmutable::now('UTC')]);
    }

    /**
     * The fields a bell row shows. Only the pointer payload, never the class name.
     *
     * @return array{id: string, kind: string|null, event: string|null, title: string|null, url: string|null, read: bool, created_at: string|null}
     */
    public static function row(DatabaseNotification $n): array
    {
        $data = is_array($n->data) ? $n->data : [];

        return [
            'id' => (string) $n->getKey(),
            'kind' => $data['kind'] ?? null,
            'event' => $data['event'] ?? null,
            'title' => $data['title'] ?? null,
            'url' => $data['url'] ?? null,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->copy()->utc()->toIso8601ZuluString(),
        ];
    }
}
