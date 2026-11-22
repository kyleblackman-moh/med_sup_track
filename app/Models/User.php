<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    public const AUTH_SOURCE_SSO = 'sso';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /**
     * Model-level defaults, so a user saved without these never falls back to
     * the database default for user_type ('external', from an early migration).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'user_type' => 'staff',
        'status' => 'active',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * Identity, status and lockout columns are deliberately not mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * user_type is compared as a string rather than cast to UserType, because
     * rows created before UserType existed may hold other values.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime', // ADMIN-1
            'password_change_required' => 'boolean',
            'mfa_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * MFA: keep the legacy mfa_enabled column in step with Fortify's columns
     * (true only once a secret is confirmed), so nothing reads a stale flag.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty(['two_factor_secret', 'two_factor_confirmed_at'])) {
                $user->mfa_enabled = $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
            }
        });
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'org_id');
    }

    public function isVendor(): bool
    {
        return $this->user_type === UserType::Vendor->value;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * MFA: mandatory TOTP applies to local accounts only. SSO accounts get MFA
     * from the directory. A row without auth_source (a model not yet
     * refreshed) counts as local, so the safe side is enforcement.
     */
    public function mustUseTwoFactor(): bool
    {
        return (bool) config('auth.mfa_enforce') && $this->auth_source !== self::AUTH_SOURCE_SSO;
    }
}
