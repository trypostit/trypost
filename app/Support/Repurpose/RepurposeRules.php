<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Rules\ContentTypeMatchesPlatform;
use App\Services\Repurpose\SourceFetcherFactory;
use Illuminate\Validation\Rule;

/**
 * The web, API and MCP entry points all write the same repurpose payload, and
 * FormRequest::validated() drops every key without a rule — so a rule declared
 * in only one of them silently loses that field in the others. They read the
 * payload's rules from here for the same reason they read the meta rules from
 * DestinationMetaRules.
 */
class RepurposeRules
{
    /**
     * @return array<string, mixed>
     */
    public static function settings(?string $workspaceId, bool $sourceRequired): array
    {
        return [
            'source_social_account_id' => [
                $sourceRequired ? 'required' : 'sometimes',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $workspaceId)
                    ->where('is_active', true)
                    ->whereIn('platform', array_map(
                        fn (Platform $platform): string => $platform->value,
                        SourceFetcherFactory::supportedPlatforms(),
                    )),
            ],
            'source_format' => ['sometimes', Rule::enum(SourceFormat::class)],
            'publish_mode' => ['sometimes', Rule::enum(PublishMode::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function destinations(?string $workspaceId): array
    {
        return [
            'destinations' => ['sometimes', 'array'],
            'destinations.*.social_account_id' => [
                'required',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $workspaceId),
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
            ...DestinationMetaRules::rules(),
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
            ...DestinationMetaRules::messages(),
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
            ...DestinationMetaRules::attributes(),
        ];
    }
}
