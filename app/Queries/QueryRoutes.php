<?php

namespace App\Queries;

use App\Models\QueryRoute;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * OVERNIGHT-2 (D1): the only routing logic: look the user's roles up in the
 * query_routes table (active rows only). Which routes exist, which go via the
 * CMO and which query type each carries are data in that table.
 */
final class QueryRoutes
{
    /**
     * The active routes $user may raise a query on, by their roles.
     *
     * @return Collection<int, QueryRoute>
     */
    public static function forRaiser(User $user): Collection
    {
        $roles = $user->getRoleNames()->all();

        if ($roles === []) {
            return collect();
        }

        return QueryRoute::query()->active()->whereIn('from_role', $roles)->orderBy('id')->get();
    }

    public static function find(int $id): ?QueryRoute
    {
        return QueryRoute::query()->active()->find($id);
    }
}
