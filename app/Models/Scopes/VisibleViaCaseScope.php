<?php

namespace App\Models\Scopes;

use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Defence in depth for case child tables (requisition, quotations, awards,
 * purchase orders, stock expiries): a row is visible only if its case is.
 *
 * Reuses VisibleToUserScope::applyFor, so the visibility rule lives in one
 * place. Fails closed: no authenticated user, no rows.
 */
class VisibleViaCaseScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        $visibleCases = ProcurementCase::query()->withoutGlobalScopes();
        (new VisibleToUserScope)->applyFor($visibleCases, $user instanceof User ? $user : null);

        $builder->whereIn($model->qualifyColumn('case_id'), $visibleCases->select('cases.id'));
    }
}
