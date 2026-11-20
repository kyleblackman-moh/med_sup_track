<?php

namespace App\Models;

use App\Enums\QueryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * OVERNIGHT-2 (D1): who may raise a query to whom, and whether it travels via
 * the CMO. Data, not code: rows are read by role name (the user's roles) and
 * only active rows count. Seeded by QueryRouteSeeder.
 */
class QueryRoute extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'via_cmo' => 'boolean',
            'active' => 'boolean',
            'query_type' => QueryType::class,
        ];
    }

    /** @param  Builder<QueryRoute>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
