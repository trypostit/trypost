<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($repurpose, $attributes): Repurpose {
            $locked = Repurpose::query()->whereKey($repurpose->id)->lockForUpdate()->firstOrFail();

            $locked->update($attributes);
            $locked = $locked->fresh();

            if ($locked->status === Status::Active) {
                ActivateRepurpose::assertPublishable($locked);
            }

            return $locked;
        });
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
