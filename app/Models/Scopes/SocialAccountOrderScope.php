<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Default channel order: position, then created_at and id. Skipped when the
 * query already orders, groups, is DISTINCT, unions or selects only raw
 * expressions (relation count sub-queries); dropped again for aggregates,
 * since PostgreSQL rejects an ORDER BY column that is neither grouped nor
 * aggregated.
 */
class SocialAccountOrderScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $query = $builder->getQuery();

        if (! $this->acceptsDefaultOrder($query)) {
            return;
        }

        $builder
            ->orderBy($model->qualifyColumn('position'))
            ->orderBy($model->qualifyColumn('created_at'))
            ->orderBy($model->qualifyColumn($model->getKeyName()));

        $query->beforeQuery(function (QueryBuilder $query): void {
            if ($query->aggregate !== null) {
                $query->orders = null;
                $query->bindings['order'] = [];
            }
        });
    }

    private function acceptsDefaultOrder(QueryBuilder $query): bool
    {
        if ($query->aggregate !== null || filled($query->orders) || filled($query->groups) || $query->distinct !== false || filled($query->unions)) {
            return false;
        }

        $columns = $query->columns ?? [];

        return $columns === [] || array_filter($columns, fn (mixed $column): bool => ! $column instanceof Expression) !== [];
    }
}
