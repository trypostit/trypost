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

        try {
            return DB::transaction(function () use ($repurpose, $attributes): Repurpose {
                $locked = Repurpose::query()->whereKey($repurpose->id)->lockForUpdate()->firstOrFail();

                $locked->fill($attributes);

                if ($locked->isDirty(['source_social_account_id', 'source_format']) && $locked->activated_at !== null) {
                    $locked->activated_at = now();
                }

                $locked->save();
                $locked = $locked->fresh();

                if ($locked->status === Status::Active) {
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
}
