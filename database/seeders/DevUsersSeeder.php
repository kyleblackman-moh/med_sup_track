<?php

namespace Database\Seeders;

use App\Enums\OrganisationType;
use App\Enums\Role;
use App\Enums\UserType;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * FAKE, LOCAL-ONLY users: one per role, for trying out login and permissions.
 *
 * Not registered in DatabaseSeeder. Run explicitly:
 *   php artisan db:seed --class=DevUsersSeeder
 *
 * Each run generates a new random password per user and prints it ONCE to the
 * console. Passwords are not stored or logged anywhere else.
 */
class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DevUsersSeeder only runs when APP_ENV=local.');
        }

        $moh = Organisation::where('type', OrganisationType::Moh)->first()
            ?? throw new RuntimeException('MOH organisation missing. Run OrganisationSeeder first.');

        $testVendor = Organisation::firstOrCreate(
            ['name' => 'Test Vendor'],
            ['type' => OrganisationType::Vendor, 'active' => true],
        );

        $rows = [];

        foreach (Role::cases() as $role) {
            $isVendor = $role === Role::VendorApplicant;
            $email = str_replace('_', '-', $role->value).'@example.test';
            $password = Str::password(20);

            $user = User::firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => 'Dev '.Str::headline($role->value),
                'password' => $password,
                'org_id' => $isVendor ? $testVendor->id : $moh->id,
                'user_type' => $isVendor ? UserType::Vendor->value : UserType::Staff->value,
                'auth_source' => 'local',
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $user->syncRoles([$role->value]);

            $rows[] = [$role->value, $email, $password];
        }

        $this->command->warn('Fake local users. These passwords are shown once and not stored anywhere:');
        $this->command->table(['Role', 'Email', 'Password'], $rows);
    }
}
