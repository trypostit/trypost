<?php

declare(strict_types=1);

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Counting rows in SQL instead of loading them: the raw aggregates the
 * query builder has no method for live here, and nowhere else.
 */
trait TalliesRows
{
    /**
     * One row per distinct value of the columns, with how many rows hold it
     * as `total`. A column may carry an alias (`posts.error_context->category
     * as category`); the group uses the column itself.
     *
     * @param  Builder<static>  $query
     * @param  list<string>  $columns
     */
    public function scopeTallyBy(Builder $query, array $columns): void
    {
        $query->select($columns)
            ->selectRaw('count(*) as total')
            ->groupBy(collect($columns)->map(fn (string $column): string => Str::before($column, ' as '))->all());
    }

    /**
     * Adds each group's earliest value of a column, as `$alias`, after {@see self::scopeTallyBy()}.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithEarliest(Builder $query, string $column, string $alias): void
    {
        $grammar = $query->getQuery()->getGrammar();

        $query->selectRaw("min({$grammar->wrap($column)}) as {$grammar->wrap($alias)}");
    }

    /**
     * Adds each group's latest value of a column, as `$alias`.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithLatest(Builder $query, string $column, string $alias): void
    {
        $grammar = $query->getQuery()->getGrammar();

        $query->selectRaw("max({$grammar->wrap($column)}) as {$grammar->wrap($alias)}");
    }
}
