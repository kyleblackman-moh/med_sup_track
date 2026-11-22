<?php

namespace App\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IT-ADMIN: end every session of a user now. The remember-me token is
 * replaced and the user's rows in the sessions table are deleted (session
 * rows are not records: deleting them is how a session ends). Call inside the
 * caller's transaction. EnsureAccountActive covers other session drivers for
 * a disabled account.
 *
 * @return int the number of sessions ended
 */
final class SessionRevoker
{
    public static function revoke(User $user): int
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        return DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
