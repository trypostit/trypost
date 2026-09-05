<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Models\Repurpose;

class UpdateRepurpose
{
    /**
     * Changing the source account resets the watermark: media ids from another
     * account are unrelated, and without the reset the new source's whole back
     * catalogue would look new.
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

        if (($destinations = data_get($data, 'destinations')) !== null) {
            $attributes['destinations'] = $destinations;
        }

        $repurpose->update($attributes);

        return $repurpose->fresh();
    }
}
