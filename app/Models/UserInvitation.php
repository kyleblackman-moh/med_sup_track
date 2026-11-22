<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * IT-ADMIN (spec 8.2): one emailed activation link for a pending account. Only
 * the sha256 of the token is stored. A link is open while accepted_at,
 * revoked_at are null and expires_at is in the future. Rows are never deleted:
 * a resend revokes the earlier link (revoked_at), activation accepts it.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $revoked_at
 */
class UserInvitation extends Model
{
    use HasUlids;

    protected $table = 'user_invitations';

    /** Nothing is mass assignable: AccountActivations sets every column. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Activation links are never deleted; revoke them.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
