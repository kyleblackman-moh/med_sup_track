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

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /** IT-ADMIN: created by an administrator; waiting for the person to use their activation link. */
    public const STATUS_INVITED = 'invited';

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
            'two_factor_confirmed_at' => 'datetime', // MFA-1
        ];
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

    public function isPendingActivation(): bool
    {
        return $this->status === self::STATUS_INVITED;
    }

    public function hasMfa(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
