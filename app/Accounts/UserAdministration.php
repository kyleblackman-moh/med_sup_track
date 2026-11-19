<?php

namespace App\Accounts;

use App\Enums\AuditAction;
use App\Enums\OrganisationType;
use App\Enums\Role;
use App\Enums\UserType;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\AccountActivation;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ADMIN-1: what Admin > Users changes. Callers have already authorised
 * (UserPolicy). Audit rows carry ids and fixed codes only, never a name,
 * email or anything typed.
 *
 * Role and organisation pairing: a vendor role belongs to an active VENDOR
 * organisation (required); every other role to the single MOH organisation
 * (assigned here; the form sends none).
 */
final class UserAdministration
{
    public const PASSWORD_LENGTH = 32;

    /**
     * Create an account with a random password nobody sees, then email the
     * person a link to choose their own (AccountActivation).
     *
     * @return array{0: User, 1: bool} the user, and whether the email went out
     */
    public function create(User $actor, string $name, string $email, Role $role, ?string $organisationId): array
    {
        $organisation = $this->organisationFor($role, $organisationId);

        $user = DB::transaction(function () use ($actor, $name, $email, $role, $organisation) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                // Unknown to everyone: the person sets their own through the emailed link.
                'password' => Hash::make(Str::password(self::PASSWORD_LENGTH)),
                'org_id' => $organisation->id,
                'user_type' => $role->userType()->value,
                'auth_source' => 'local',
                'status' => User::STATUS_ACTIVE,
                'failed_login_attempts' => 0,
                // They choose the password themselves, so no forced change after the link.
                'password_change_required' => false,
                'invited_by' => $actor->id,
            ])->save();

            $user->assignRole($role->value);

            Audit::record(
                AuditAction::UserCreated,
                ['type' => 'user', 'id' => $user->id],
                null,
                [
                    'user_id' => $user->id,
                    'role' => $role->value,
                    'organisation_id' => $organisation->id,
                    'created_via' => 'admin',
                ],
                $actor,
            );

            return $user;
        });

        return [$user, $this->sendActivation($user)];
    }

    /**
     * Email a fresh link (a new token replaces any earlier one). False when
     * the mail could not be sent; the error is logged, never shown.
     */
    public function sendActivation(User $user): bool
    {
        try {
            $user->notify(new AccountActivation(Password::broker()->createToken($user)));

            return true;
        } catch (Throwable $e) {
            Log::error('Account activation email failed', ['user_id' => $user->id, 'exception' => $e::class]);

            return false;
        }
    }

    /** True when mail goes to the log or nowhere (MAIL_MAILER log or array), so nothing reaches an inbox. */
    public static function mailIsNotDelivered(): bool
    {
        return in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * The organisation a new account with this role joins.
     *
     * @throws ValidationException when the pairing is wrong
     */
    private function organisationFor(Role $role, ?string $organisationId): Organisation
    {
        if ($role->userType() === UserType::Vendor) {
            $organisation = $organisationId === null ? null : Organisation::query()
                ->whereKey($organisationId)
                ->where('type', OrganisationType::Vendor->value)
                ->where('active', true)
                ->first();

            if ($organisation === null) {
                throw ValidationException::withMessages(['organisation' => 'Choose the vendor organisation this user belongs to.']);
            }

            return $organisation;
        }

        if ($organisationId !== null) {
            throw ValidationException::withMessages(['organisation' => 'Staff roles belong to the Ministry of Health. Leave the organisation empty.']);
        }

        $moh = Organisation::where('type', OrganisationType::Moh->value)->get();
        if ($moh->count() !== 1) {
            throw ValidationException::withMessages(['role' => 'The Ministry of Health organisation is not set up. Nothing was changed.']);
        }

        return $moh->first();
    }
}
