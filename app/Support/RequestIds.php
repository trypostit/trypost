<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class RequestIds
{
    /**
     * @param  Collection<int, mixed>  $values
     * @return list<string>
     */
    public static function uuidList(Collection $values): array
    {
        return $values
            ->filter(fn (mixed $value): bool => is_string($value) && Str::isUuid($value))
            ->values()
            ->all();
    }

    /**
     * The requested ids that are among the allowed ones, in the allowed order; anything else is dropped silently.
     *
     * @param  Collection<int, mixed>  $requested
     * @param  Collection<int, string>  $allowedIds
     * @return list<string>
     */
    public static function selected(Collection $requested, Collection $allowedIds): array
    {
        return $allowedIds->intersect(self::uuidList($requested))->values()->all();
    }
}
