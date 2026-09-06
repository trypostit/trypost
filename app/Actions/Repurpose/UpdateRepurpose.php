<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use Illuminate\Support\Facades\DB;

class UpdateRepurpose
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(Repurpose $repurpose, array $data): Repurpose
    {
        $attributes = [];

        if (($sourceAccountId = data_get($data, 'source_social_account_id')) !== null
            && $sourceAccountId !== $repurpose->source_social_account_id) {
            $attributes['source_social_account_id'] = $sourceAccountId;
            $attributes['activated_at'] = $repurpose->activated_at === null ? null : now();
        }

        if (($format = SourceFormat::tryFrom((string) data_get($data, 'source_format'))) !== null
            && $format !== $repurpose->source_format) {
            $attributes['source_format'] = $format;
            $attributes['activated_at'] = $repurpose->activated_at === null ? null : now();
        }

        if (($destinations = data_get($data, 'destinations')) !== null) {
            $attributes['destinations'] = $destinations;
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
}
