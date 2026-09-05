<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\SourceFormat;
use App\Rules\ContentTypeMatchesPlatform;
use App\Services\Repurpose\SourceFetcherFactory;
use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RepurposeRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(?string $workspaceId = null): array
    {
        return [
            'source_social_account_id' => [
                'required',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $workspaceId)
                    ->where('is_active', true)
                    ->whereIn('platform', array_map(
                        fn ($platform): string => $platform->value,
                        SourceFetcherFactory::supportedPlatforms(),
                    )),
            ],
            'source_format' => ['sometimes', Rule::enum(SourceFormat::class)],
            'destinations' => ['sometimes', 'array'],
            'destinations.*.social_account_id' => [
                'required',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $workspaceId)
                    ->where('is_active', true),
            ],
            'destinations.*.content_type' => [
                'required',
                'string',
                Rule::enum(ContentType::class),
                new ContentTypeMatchesPlatform,
                fn (string $attribute, mixed $value, callable $fail) => ContentType::tryFrom((string) $value)?->supportsVideo() === false
                    ? $fail(__('repurposes.errors.destination_needs_video'))
                    : null,
            ],
            ...self::forDestinations(PostPlatformMetaRules::rules()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'destinations.*.social_account_id.exists' => __('repurposes.errors.destination_unavailable'),
            'source_social_account_id.exists' => __('repurposes.errors.source_unavailable'),
            ...self::forDestinations(PostPlatformMetaRules::messages()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'destinations.*.social_account_id' => __('repurposes.destinations.title'),
            'destinations.*.content_type' => __('repurposes.destinations.publish_as'),
            'source_social_account_id' => __('repurposes.source.title'),
            ...self::forDestinations(PostPlatformMetaRules::attributes()),
        ];
    }

    /**
     * @param  array<string, mixed>  $entries
     * @return array<string, mixed>
     */
    private static function forDestinations(array $entries): array
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
