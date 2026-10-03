<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Rules\ContentFitsPlatformLimits;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Rules\PostContentFitsMaxLength;
use App\Support\PostMediaRules;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $status = $this->input('status');

        $enforcesMediaCompatibility = in_array(
            $status,
            [Status::Scheduled->value, Status::Publishing->value],
            true,
        );

        return [
            'status' => ['required', 'string', Rule::in([Status::Draft->value, Status::Scheduled->value, Status::Publishing->value])],
            'content' => [
                'nullable',
                'string',
                new PostContentFitsMaxLength,
                Rule::when(
                    $enforcesMediaCompatibility,
                    [new ContentFitsPlatformLimits($this->resolveSelectedPlatforms(), PostPlatformMetaRules::metaByKey($this->input('platforms', []), 'id'), PostPlatformMetaRules::contentTypesByKey($this->input('platforms', []), 'id', $this->storedContentTypes()))]
                ),
            ],
            ...PostMediaRules::hostedRules(),
            'scheduled_at' => PostStatusRules::scheduledAtRules($this->route('post'), $status, $this->filled('queue')),
            'queue' => PostStatusRules::queueRules(),
            'social_account_id' => ['prohibited'],
            'content_type' => ['sometimes', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
            'meta' => ['sometimes', 'array'],
            'platforms' => ['sometimes', 'array'],
            'platforms.*.id' => ['required', 'uuid', Rule::exists('post_platforms', 'id')->where('post_id', $this->route('post')->id)],
            'platforms.*.content_type' => [
                $enforcesMediaCompatibility ? 'required' : 'sometimes',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
                Rule::when($enforcesMediaCompatibility, [new ContentTypeCompatibleWithMedia(workspace: $this->route('post')->workspace)]),
            ],
            ...PostPlatformMetaRules::rules(),
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $this->user()->currentWorkspace->id)->withoutTrashed()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...PostPlatformMetaRules::messages(), ...PostStatusRules::queueMessages()];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return PostPlatformMetaRules::attributes();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->isPublishingOrScheduling()) {
                return;
            }

            $platforms = $this->input('platforms', []);
            $ids = collect($platforms)->pluck('id')->filter()->all();

            $platformsById = $this->route('post')
                ->postPlatforms()
                ->whereIn('id', $ids)
                ->pluck('platform', 'id');

            PostPlatformMetaRules::addRequiredOnPublishErrors(
                $validator,
                $platforms,
                fn ($platform) => $platformsById[data_get($platform, 'id')] ?? null,
            );
        });
    }

    private function isPublishingOrScheduling(): bool
    {
        return in_array(
            $this->input('status'),
            [Status::Scheduled->value, Status::Publishing->value],
            true,
        );
    }

    /**
     * @return Collection<int|string, Platform|SocialAccount>
     */
    /**
     * @return array<string, string|null>
     */
    private function storedContentTypes(): array
    {
        return $this->route('post')->postPlatforms()->pluck('content_type', 'id')
            ->map(fn (?ContentType $contentType): ?string => $contentType?->value)
            ->all();
    }

    private function resolveSelectedPlatforms(): Collection
    {
        $ids = collect($this->input('platforms', []))->pluck('id')->filter()->all();

        if (empty($ids)) {
            return collect();
        }

        return $this->route('post')
            ->postPlatforms()
            ->whereIn('id', $ids)
            ->with('socialAccount')
            ->get()
            ->mapWithKeys(fn (PostPlatform $postPlatform): array => [
                $postPlatform->id => $postPlatform->socialAccount ?? $postPlatform->platform,
            ]);
    }
}
