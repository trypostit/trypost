<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Repurpose;

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Models\Repurpose;
use App\Rules\ContentTypeMatchesPlatform;
use App\Rules\Repurpose\SourceIsFree;
use App\Services\Repurpose\SourceFetcherFactory;
use App\Support\Repurpose\DestinationMetaRules;
use App\Support\Repurpose\SourceIsNotADestination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRepurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function workspaceId(): ?string
    {
        return $this->user()->currentWorkspace?->id;
    }

    private function repurpose(): Repurpose
    {
        return $this->route('repurpose');
    }

    private function sourceFormat(): SourceFormat
    {
        return SourceFormat::tryFrom((string) $this->input('source_format'))
            ?? $this->repurpose()->source_format
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
                new SourceIsFree($this->workspaceId(), $this->sourceFormat(), $this->repurpose()->id),
            ],
            'source_format' => ['sometimes', Rule::enum(SourceFormat::class)],
            'publish_mode' => ['sometimes', Rule::enum(PublishMode::class)],
            'destinations' => ['sometimes', 'array'],
            'destinations.*.social_account_id' => [
                'required',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $this->workspaceId()),
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            SourceIsNotADestination::addErrors(
                $validator,
                (array) $this->input('destinations', []),
                $this->input('source_social_account_id', $this->route('repurpose')->source_social_account_id),
            );
        });
    }
}
