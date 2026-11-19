<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** NOTIF-1: a signed-in user changed their own password (Settings). A reset fires Laravel's PasswordReset. */
class PasswordChanged
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
