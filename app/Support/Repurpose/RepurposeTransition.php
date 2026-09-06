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
     * Every lifecycle change reads the status and writes it back, so it holds
     * the row across both halves and re-reads it inside: the caller's copy was
     * loaded before the request, and two callers arriving together would each
     * pass the check the other is about to invalidate.
     *
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
     * The system's transition. A status that moved on since the caller read it
     * is an outcome, not an error — two accounts of one repurpose dying in the
     * same sweep would otherwise throw out of an observer and take the sweep
     * with it. Returning null also makes the pause idempotent, so a repurpose
     * that is already stopped is left exactly as it was.
     *
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
