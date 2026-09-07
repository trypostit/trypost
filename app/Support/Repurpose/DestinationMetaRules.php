<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class DestinationMetaRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return self::reKey(PostPlatformMetaRules::rules());
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return self::reKey(PostPlatformMetaRules::messages());
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::reKey(PostPlatformMetaRules::attributes());
    }

    public static function enforcedFor(Repurpose $repurpose): bool
    {
        return $repurpose->status === Status::Active;
    }

    /**
     * @param  array<int, mixed>  $destinations
     */
    public static function addRequiredErrors(Validator $validator, array $destinations, ?string $workspaceId): void
    {
        $platforms = SocialAccount::query()
            ->where('workspace_id', $workspaceId)
            ->findMany(array_map(
                fn (mixed $destination): mixed => data_get($destination, 'social_account_id'),
                $destinations,
            ))
            ->pluck('platform', 'id');

        foreach ($destinations as $index => $destination) {
            $violation = PostPlatformMetaRules::requiredMetaViolation(
                $platforms->get(data_get($destination, 'social_account_id')),
                data_get($destination, 'meta'),
            );

            if ($violation !== null) {
                [$field, $message] = $violation;
                $validator->errors()->add("destinations.{$index}.meta.{$field}", $message);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $destinations
     */
    public static function assertRequired(array $destinations, ?string $workspaceId): void
    {
        $validator = ValidatorFacade::make([], []);

        self::addRequiredErrors($validator, $destinations, $workspaceId);

        if ($validator->errors()->isNotEmpty()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * @param  array<string, mixed>  $entries
     * @return array<string, mixed>
     */
    private static function reKey(array $entries): array
    {
        $destinations = [];

        foreach ($entries as $key => $entry) {
            if (! Str::startsWith($key, 'platforms.*.meta')) {
                continue;
            }

            $destinations[Str::replaceFirst('platforms.*.', 'destinations.*.', $key)] = $entry;
        }

        return $destinations;
    }
}
