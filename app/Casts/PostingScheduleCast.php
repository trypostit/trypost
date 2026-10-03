<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\PostingSchedule;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<PostingSchedule|null, PostingSchedule|array<int, mixed>|null>
 */
class PostingScheduleCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PostingSchedule
    {
        if ($value === null) {
            return null;
        }

        return PostingSchedule::fromArray(json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $schedule = match (true) {
            $value instanceof PostingSchedule => $value,
            is_array($value) => PostingSchedule::fromArray($value),
            default => throw new InvalidArgumentException('Invalid posting schedule.'),
        };

        return json_encode($schedule->toArray(), JSON_THROW_ON_ERROR);
    }
}
