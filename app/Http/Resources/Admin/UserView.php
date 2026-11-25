<?php

namespace App\Http\Resources\Admin;

use App\Enums\Role;
use App\Enums\UserType;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * ADMIN-1, IT-ADMIN: what Admin > Users sends to React about one account.
 * Plain arrays, never the model: no password, tokens, two-factor secrets or
 * lockout counters. Dates are formatted here in America/Port_of_Spain; role,
 * status and MFA labels and tones come from the server, so React never reads codes.
 */
final class UserView
{
    public const TIMEZONE = 'America/Port_of_Spain';

    public const NEVER = 'Never';

    public const EMPTY = '—';

    /** The Status filter: the three stored statuses, and "locked" (an active account inside its lockout window). */
    public const FILTER_LOCKED = 'locked';

    /** Status code => [label, tone]. */
    private const STATUSES = [
        User::STATUS_INVITED => ['Pending activation', 'info'],
        User::STATUS_ACTIVE => ['Active', 'success'],
        User::STATUS_DISABLED => ['Disabled', 'neutral'],
        self::FILTER_LOCKED => ['Locked', 'warning'],
    ];

    /** @return list<string> */
    public static function statusFilterValues(): array
    {
        return array_keys(self::STATUSES);
    }

    /** Options for the Status filter. */
    public static function statusOptions(): array
    {
        return array_map(fn (string $value) => ['value' => $value, 'label' => self::STATUSES[$value][0]], self::statusFilterValues());
    }

    /** Options for the Type filter. */
    public static function typeOptions(): array
    {
        return [
            ['value' => UserType::Staff->value, 'label' => 'Staff'],
            ['value' => UserType::Vendor->value, 'label' => 'Vendor'],
        ];
    }

    /** Options for the MFA filter. */
    public static function mfaOptions(): array
    {
        return [
            ['value' => 'yes', 'label' => 'Enrolled'],
            ['value' => 'no', 'label' => 'Not enrolled'],
        ];
    }

    /**
     * Roles in enum order, with display names. The Role filter lists them all;
     * the create and change-role forms pass $grantable, which leaves out
     * system_admin (command line only).
     */
    public static function roleOptions(bool $grantable = false): array
    {
        $roles = array_filter(Role::cases(), fn (Role $r) => ! $grantable || $r !== Role::SystemAdmin);

        return array_values(array_map(fn (Role $r) => [
            'value' => $r->value,
            'label' => $r->label(),
            // The role belongs to a vendor organisation (the form then asks for one). Display only; the server validates.
            'vendor' => $r->userType() === UserType::Vendor,
        ], $roles));
    }

    /**
     * One list row. Expects organisation and roles loaded.
     *
     * @return array<string, mixed>
     */
    public static function row(User $user): array
    {
        [$label, $tone] = self::status($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'type' => $user->isVendor() ? 'Vendor' : 'Staff',
            'organisation' => $user->organisation?->name ?? self::EMPTY,
            'role' => self::roleLabel($user),
            'status_label' => $label,
            'status_tone' => $tone,
            'mfa_label' => $user->hasMfa() ? 'Enrolled' : 'Not enrolled',
            'mfa_tone' => $user->hasMfa() ? 'success' : 'warning',
            'last_sign_in' => self::dateTime($user->last_login_at) ?? self::NEVER,
        ];
    }

    /**
     * The detail page. Expects organisation and roles loaded.
     *
     * @return array<string, mixed>
     */
    public static function detail(User $user): array
    {
        return [
            ...self::row($user),
            'role_value' => $user->getRoleNames()->first(),
            'job_title' => $user->job_title,
            'division' => $user->division,
            'is_active' => $user->isActive(),
            'is_pending' => $user->isPendingActivation(),
            'is_locked' => $user->isLocked(),
            'locked_until' => $user->isLocked() ? self::dateTime($user->locked_until) : null,
            'mfa_enrolled' => $user->hasMfa(),
            'created_on' => self::dateTime($user->created_at) ?? self::EMPTY,
            'disabled_on' => self::dateTime($user->disabled_at),
        ];
    }

    /** The display names of the user's roles (one, in practice), or a dash. */
    public static function roleLabel(User $user): string
    {
        $labels = $user->roles->map(fn ($role) => Role::tryFrom($role->name)?->label() ?? $role->name)->sort()->values();

        return $labels->isEmpty() ? self::EMPTY : $labels->implode(', ');
    }

    /** @return array{0: string, 1: string} */
    private static function status(User $user): array
    {
        if ($user->isActive() && $user->isLocked()) {
            return self::STATUSES[self::FILTER_LOCKED];
        }

        return self::STATUSES[$user->status] ?? [ucfirst((string) $user->status), 'neutral'];
    }

    public static function dateTime(?CarbonInterface $value): ?string
    {
        return $value?->copy()->setTimezone(self::TIMEZONE)->format('j M Y, g:i a');
    }
}
