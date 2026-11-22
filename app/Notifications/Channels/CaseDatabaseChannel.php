<?php

namespace App\Notifications\Channels;

use App\Notifications\CaseNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * NOTIF-2: Laravel's database channel, plus the notifications.case_id column,
 * so a case page can mark its own notifications read in one update.
 */
final class CaseDatabaseChannel extends DatabaseChannel
{
    /** @return array<string, mixed> */
    protected function buildPayload($notifiable, Notification $notification)
    {
        return [
            ...parent::buildPayload($notifiable, $notification),
            'case_id' => $notification instanceof CaseNotification ? $notification->caseId : null,
        ];
    }
}
