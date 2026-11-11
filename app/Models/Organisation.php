<?php

namespace App\Models;

use App\Enums\OrganisationType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'short_name',
        'type',
        'email',
        'phone',
        'status',
        'address',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganisationType::class,
            'active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'org_id');
    }

    /** Filtered by VisibleToUserScope like every other case query. */
    public function cases(): HasMany
    {
        return $this->hasMany(ProcurementCase::class, 'org_id');
    }
}
