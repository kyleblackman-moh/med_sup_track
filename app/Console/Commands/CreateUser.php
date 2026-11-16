<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\OrganisationType;
use App\Enums\Role;
use App\Enums\UserType;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The real way to create an account, in every environment (no admin UI yet).
 *
 * Staff roles join the single MOH organisation; vendor_applicant needs
 * --organisation (a vendor organisation's ULID or exact name). A random
 * password is printed ONCE and stored only as a hash; the user must change it
 * at first login (password_change_required). The audit row carries the new
 * user id, role, organisation id and "console": no name, email or password.
 */
class CreateUser extends Command
{
    protected $signature = 'app:user-create
        {name : Full name}
        {email : Sign-in email}
        {role : One of the roles, for example cmo or vendor_applicant}
        {--organisation= : Vendor organisation ULID or exact name (vendor_applicant only)}';

    protected $description = 'Create a user with one role and a one-time password that must be changed at first login';

    public const PASSWORD_LENGTH = 24;

    public function handle(): int
    {
        if (app()->isLocal()) {
            $this->warn('APP_ENV is local: this account is created in the local database.');
        }

        $name = trim((string) $this->argument('name'));
        $email = Str::lower(trim((string) $this->argument('email')));
        $role = Role::tryFrom((string) $this->argument('role'));
        $orgOption = $this->option('organisation');

        if ($role === null) {
            $this->error('Unknown role "'.$this->argument('role').'". Valid roles: '.implode(', ', array_map(fn (Role $r) => $r->value, Role::cases())).'.');

            return self::FAILURE;
        }

        $validator = Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('A user with that email already exists. Nothing was changed.');

            return self::FAILURE;
        }

        $isVendor = $role === Role::VendorApplicant;
        $organisation = $isVendor ? $this->vendorOrganisation($orgOption) : $this->mohOrganisation($orgOption);
        if ($organisation === null) {
            return self::FAILURE;
        }

        $password = Str::password(self::PASSWORD_LENGTH);

        $user = DB::transaction(function () use ($name, $email, $role, $isVendor, $organisation, $password) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'org_id' => $organisation->id,
                'user_type' => $isVendor ? UserType::Vendor->value : UserType::Staff->value,
                'auth_source' => 'local',
                'status' => User::STATUS_ACTIVE,
                'failed_login_attempts' => 0,
                'password_change_required' => true,
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
                    'created_via' => 'console',
                ],
                Audit::ACTOR_SYSTEM,
            );

            return $user;
        });

        $this->info("Created user #{$user->id} ({$role->value}).");
        $this->line('One-time password (shown once, not stored anywhere else): '.$password);
        $this->line('The user must change it at first sign-in.');

        return self::SUCCESS;
    }

    private function vendorOrganisation(mixed $option): ?Organisation
    {
        if (! is_string($option) || trim($option) === '') {
            $this->error('vendor_applicant needs --organisation (a vendor organisation ULID or exact name).');

            return null;
        }

        $option = trim($option);
        $organisation = Organisation::query()
            ->where('type', OrganisationType::Vendor->value)
            ->where(fn ($q) => $q->where('id', $option)->orWhere('name', $option))
            ->first();

        if ($organisation === null) {
            $this->error("No vendor organisation with id or name \"{$option}\".");
        }

        return $organisation;
    }

    private function mohOrganisation(mixed $option): ?Organisation
    {
        if ($option !== null) {
            $this->error('Staff roles join the MOH organisation automatically; do not pass --organisation.');

            return null;
        }

        $moh = Organisation::where('type', OrganisationType::Moh->value)->get();
        if ($moh->count() !== 1) {
            $this->error("Expected exactly one MOH organisation, found {$moh->count()}. Nothing was changed.");

            return null;
        }

        return $moh->first();
    }
}
