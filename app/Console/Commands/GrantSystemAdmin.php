<?php

namespace App\Console\Commands;

use App\Accounts\SessionRevoker;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AdminActionReason;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IT-ADMIN: the only way to give an account the System Admin role. The UI
 * cannot (UserAdministration::refuseSystemAdmin). The account must be an
 * active or pending MOH staff account; its role is replaced, its sessions end,
 * and one user.admin_granted audit row is written (actor "system", the old
 * roles, reason_id and reason_length; the reason text is in
 * admin_action_reasons).
 */
class GrantSystemAdmin extends Command
{
    protected $signature = 'app:user-grant-admin
        {email : The staff account to make System Admin}
        {--reason= : Why (required; for example the change ticket)}';

    protected $description = 'Give a staff account the System Admin role (command line only, audited)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('Give a --reason (for example the change ticket). Nothing was changed.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $this->error('No user with that email. Nothing was changed.');

            return self::FAILURE;
        }

        if ($user->isVendor()) {
            $this->error('A vendor account cannot be System Admin. Nothing was changed.');

            return self::FAILURE;
        }

        if ($user->status === User::STATUS_DISABLED) {
            $this->error('The account is disabled. Enable it first. Nothing was changed.');

            return self::FAILURE;
        }

        if ($user->hasRole(Role::SystemAdmin->value)) {
            $this->info("User #{$user->id} is already System Admin. Nothing was changed.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $reason) {
            $old = $user->getRoleNames()->values()->all();

            $user->syncRoles([Role::SystemAdmin->value]);
            $ended = SessionRevoker::revoke($user);

            Audit::record(AuditAction::UserAdminGranted, ['type' => 'user', 'id' => $user->id], null, [
                'user_id' => $user->id,
                'old_roles' => $old,
                'new_role' => Role::SystemAdmin->value,
                'sessions_ended' => $ended,
                'granted_via' => 'console',
                ...AdminActionReason::store(AuditAction::UserAdminGranted->value, null, $user, $reason),
            ], Audit::ACTOR_SYSTEM);
        });

        $this->info("User #{$user->id} is now System Admin. Their sessions have ended.");

        return self::SUCCESS;
    }
}
