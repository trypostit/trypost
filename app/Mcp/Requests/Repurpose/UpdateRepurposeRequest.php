<?php

declare(strict_types=1);

namespace App\Mcp\Requests\Repurpose;

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Models\Repurpose;
use App\Rules\ContentTypeMatchesPlatform;
use App\Rules\Repurpose\NotTheSourceAccount;
use App\Rules\Repurpose\SourceIsFree;
use App\Services\Repurpose\SourceFetcherFactory;
use App\Support\Repurpose\DestinationMetaRules;
use Illuminate\Validation\Rule;

class UpdateRepurposeRequest
{
    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function rules(?string $workspaceId = null, ?Repurpose $repurpose = null, array $payload = []): array
    {
        $sourceAccountId = data_get($payload, 'source_social_account_id', $repurpose?->source_social_account_id);
        $sourceFormat = SourceFormat::tryFrom((string) data_get($payload, 'source_format'))
            ?? $repurpose?->source_format
            ?? SourceFormat::Reel;

        return [
            'repurpose_id' => ['required', 'string', 'uuid'],
            'source_social_account_id' => [
                'sometimes',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $workspaceId)
                    ->where('is_active', true)
                    ->whereIn('platform', array_map(
                        fn (Platform $platform): string => $platform->value,
                        SourceFetcherFactory::supportedPlatforms(),
                    )),
                new SourceIsFree($workspaceId, $sourceFormat, $repurpose?->id),
            ],
            'source_format' => ['sometimes', Rule::enum(SourceFormat::class)],
            'publish_mode' => ['sometimes', Rule::enum(PublishMode::class)],
            'destinations' => ['sometimes', 'array'],
            'destinations.*.social_account_id' => [
                'required',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $workspaceId)
                    ->where('is_active', true),
                new NotTheSourceAccount(is_string($sourceAccountId) ? $sourceAccountId : null),
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
}
