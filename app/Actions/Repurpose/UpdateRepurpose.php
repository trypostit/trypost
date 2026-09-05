<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Models\Repurpose;

class UpdateRepurpose
{
    /**
     * Changing the source account or the watched format resets the watermark:
     * the media the repurpose now looks at is unrelated to what it saw before,
     * and without the reset the whole back catalogue would look new.
     *
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

        return $repurpose->fresh();
    }
}
