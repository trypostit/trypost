<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class DestinationMetaRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            ...PostPlatformMetaRules::rules('destinations.*.meta'),
            'destinations.*.meta.thread_replies.*.media.*.url' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return PostPlatformMetaRules::messages('destinations.*.meta');
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return PostPlatformMetaRules::attributes('destinations.*.meta');
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
}
