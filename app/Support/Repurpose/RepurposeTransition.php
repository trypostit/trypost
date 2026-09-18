<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepurposeTransition
{
    /**
     * @param  array<int, Status>  $from
     * @param  callable(Repurpose): void  $change
     */
    public static function apply(Repurpose $repurpose, array $from, string $message, callable $change): Repurpose
    {
        return DB::transaction(function () use ($repurpose, $from, $message, $change): Repurpose {
            $locked = Repurpose::query()->whereKey($repurpose->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => $message]);
            }

            $change($locked);

            return $locked->fresh();
        });
    }

    /**
     * @param  array<int, Status>  $from
     * @param  callable(Repurpose): void  $change
     */
    public static function applyIfPossible(Repurpose $repurpose, array $from, callable $change): ?Repurpose
    {
        return DB::transaction(function () use ($repurpose, $from, $change): ?Repurpose {
            $locked = Repurpose::query()->whereKey($repurpose->id)->lockForUpdate()->first();

            if ($locked === null || ! in_array($locked->status, $from, true)) {
                return null;
            }

            $change($locked);

            return $locked->fresh();
        });
    }
}
