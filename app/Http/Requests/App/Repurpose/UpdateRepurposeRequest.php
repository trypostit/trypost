<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Repurpose;

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
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRepurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('repurpose'));
    }

    private function workspaceId(): ?string
    {
        return $this->user()->current_workspace_id;
    }

    private function repurpose(): ?Repurpose
    {
        $repurpose = $this->route('repurpose');

        return $repurpose instanceof Repurpose ? $repurpose : null;
    }

    /** The account this repurpose will watch once the request is applied. */
    private function sourceAccountId(): ?string
    {
        $id = $this->input('source_social_account_id', $this->repurpose()?->source_social_account_id);

        return is_string($id) ? $id : null;
    }

    /** The format this repurpose will watch once the request is applied. */
    private function sourceFormat(): SourceFormat
    {
        return SourceFormat::tryFrom((string) $this->input('source_format'))
            ?? $this->repurpose()?->source_format
            ?? SourceFormat::Reel;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_social_account_id' => [
                'sometimes',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $this->workspaceId())
                    ->where('is_active', true)
                    ->whereIn('platform', array_map(
                        fn (Platform $platform): string => $platform->value,
                        SourceFetcherFactory::supportedPlatforms(),
                    )),
                new SourceIsFree($this->workspaceId(), $this->sourceFormat(), $this->repurpose()?->id),
            ],
            'source_format' => ['sometimes', Rule::enum(SourceFormat::class)],
            'publish_mode' => ['sometimes', Rule::enum(PublishMode::class)],
            'destinations' => ['sometimes', 'array'],
            'destinations.*.social_account_id' => [
                'required',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $this->workspaceId())
                    ->where('is_active', true),
                new NotTheSourceAccount($this->sourceAccountId()),
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
    public function messages(): array
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
    public function attributes(): array
    {
        return [
            'destinations.*.social_account_id' => __('repurposes.destinations.title'),
            'destinations.*.content_type' => __('repurposes.destinations.publish_as'),
            'source_social_account_id' => __('repurposes.source.title'),
            ...DestinationMetaRules::attributes(),
        ];
    }
}
