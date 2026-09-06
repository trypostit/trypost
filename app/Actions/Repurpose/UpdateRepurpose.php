<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRepurpose
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(Repurpose $repurpose, array $data): Repurpose
    {
        $attributes = Arr::only($data, [
            'source_social_account_id',
            'source_format',
            'publish_mode',
            'destinations',
        ]);

        if (self::watchesSomethingElse($repurpose, $attributes)) {
            $attributes['activated_at'] = $repurpose->activated_at === null ? null : now();
        }

        try {
            return DB::transaction(function () use ($repurpose, $attributes): Repurpose {
                $locked = Repurpose::query()->whereKey($repurpose->id)->lockForUpdate()->firstOrFail();

                $locked->update($attributes);
                $locked = $locked->fresh();

                if ($locked->status === Status::Active) {
                    // Destinations only. The source's health is not this
                    // request's business, and checking it here would fail an
                    // unrelated edit during any window where the source is
                    // briefly unhealthy and the observer has not caught up.
                    ActivateRepurpose::assertDestinationsPublishable($locked);
                }

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'source_social_account_id' => __('repurposes.errors.source_already_used'),
            ]);
        }
    }

    /**
     * A repurpose aimed at another account or another format has a back catalogue
     * behind it that was never meant for these destinations, so the watermark
     * moves to now instead of replaying it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function watchesSomethingElse(Repurpose $repurpose, array $attributes): bool
    {
        return data_get($attributes, 'source_social_account_id', $repurpose->source_social_account_id) !== $repurpose->source_social_account_id
            || data_get($attributes, 'source_format', $repurpose->source_format->value) !== $repurpose->source_format->value;
    }
}
