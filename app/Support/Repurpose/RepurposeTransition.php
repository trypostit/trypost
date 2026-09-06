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
}
