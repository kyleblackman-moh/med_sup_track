<?php

namespace App\Http\Resources\Admin;

use App\Enums\Role;
use App\Enums\UserType;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * ADMIN-1: what Admin > Users sends to React about one account. Plain arrays,
 * never the model: no password, tokens, two-factor or lockout columns. Dates
 * are formatted here in America/Port_of_Spain; role and status labels and the
 * status tone come from the server, so React never reads codes.
 */
final class UserView
{
    public const TIMEZONE = 'America/Port_of_Spain';

    public const NEVER = 'Never';

    public const EMPTY = '—';

    /** Status code => [label, tone]. "invited" exists in the schema but nothing sets it yet. */
    private const STATUSES = [
        User::STATUS_ACTIVE => ['Active', 'success'],
        User::STATUS_DISABLED => ['Disabled', 'neutral'],
        'invited' => ['Invited', 'info'],
    ];

    /** Options for the Status filter (Active and Disabled only). */
    public static function statusOptions(): array
    {
        return [
            ['value' => User::STATUS_ACTIVE, 'label' => self::STATUSES[User::STATUS_ACTIVE][0]],
            ['value' => User::STATUS_DISABLED, 'label' => self::STATUSES[User::STATUS_DISABLED][0]],
        ];
    }

    /** Options for the Role filter and the role selects, in enum order, with display names. */
    public static function roleOptions(): array
    {
        return array_map(fn (Role $r) => [
            'value' => $r->value,
            'label' => $r->label(),
            // The role belongs to a vendor organisation (the form then asks for one). Display only; the server validates.
            'vendor' => $r->userType() === UserType::Vendor,
        ], Role::cases());
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
            'organisation' => $user->organisation?->name ?? self::EMPTY,
            'role' => self::roleLabel($user),
            'status_label' => $label,
            'status_tone' => $tone,
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
            'is_active' => $user->isActive(),
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
        return self::STATUSES[$user->status] ?? [ucfirst((string) $user->status), 'neutral'];
    }

    private static function dateTime(?CarbonInterface $value): ?string
    {
        return $value?->copy()->setTimezone(self::TIMEZONE)->format('j M Y, g:i a');
    }
}
