<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Models\Repurpose;

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

        $repurpose->update($attributes);
        $repurpose = $repurpose->fresh();

        if ($repurpose->status === Status::Active) {
            ActivateRepurpose::assertPublishable($repurpose);
        }

        return $repurpose;
    }
}
