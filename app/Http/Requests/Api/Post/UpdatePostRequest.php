<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Rules\ContentFitsPlatformLimits;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Rules\ContentTypeMatchesPostPlatform;
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

        $enforcesPlatformLimits = in_array(
            $status,
            [Status::Scheduled->value, Status::Publishing->value],
            true,
        );

        return [
            'status' => ['required', 'string', Rule::in([Status::Draft->value, Status::Scheduled->value, Status::Publishing->value])],
            'content' => [
                'nullable',
                'string',
                'max:10000',
                Rule::when(
                    $enforcesPlatformLimits,
                    [new ContentFitsPlatformLimits($this->resolveSelectedPlatforms())]
                ),
            ],
            ...PostMediaRules::rules(hosted: false),
            'platforms' => ['sometimes', 'array'],
            'platforms.*.id' => ['required', 'uuid', Rule::exists('post_platforms', 'id')->where('post_id', $this->route('post')->id)],
            'platforms.*.content_type' => [
                'sometimes',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
                new ContentTypeMatchesPostPlatform,
            ],
            ...PostPlatformMetaRules::rules(),
            'scheduled_at' => PostStatusRules::scheduledAtRules($this->route('post'), $status),
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $this->user()->currentWorkspace->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PostPlatformMetaRules::messages();
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
            if (! in_array($this->input('status'), [Status::Scheduled->value, Status::Publishing->value], true)) {
                return;
            }

            $this->addMediaCompatibilityErrors($validator);

            $platformsById = $this->resolveSelectedPlatforms();

            PostPlatformMetaRules::addRequiredOnPublishErrors(
                $validator,
                $this->input('platforms', []),
                fn ($platform) => $platformsById[data_get($platform, 'id')] ?? null,
            );
        });
    }

    /**
     * On publish/schedule, validate every platform's *effective* content_type
     * (resubmitted in this request, or its stored value) against the *effective*
     * media (the request's media when sent, otherwise the post's stored media).
     * This closes the gap where a client publishes a misconfigured post — e.g. a
     * PDF on a regular LinkedIn post — without resubmitting content_type, which a
     * field-level rule on `platforms.*.content_type` would skip.
     */
    private function addMediaCompatibilityErrors(Validator $validator): void
    {
        /** @var Post $post */
        $post = $this->route('post');

        $media = $this->has('media') ? (array) $this->input('media', []) : (array) ($post->media ?? []);

        $entries = ContentTypeCompatibleWithMedia::entriesForUpdate(
            $post,
            $this->has('platforms') ? (array) $this->input('platforms', []) : null,
        );

        foreach (ContentTypeCompatibleWithMedia::errorsFor($entries, $media) as $key => $message) {
            $validator->errors()->add($key, $message);
        }
    }

    /**
     * @return Collection<int|string, Platform>
     */
    private function resolveSelectedPlatforms(): Collection
    {
        $ids = collect($this->input('platforms', []))->pluck('id')->filter()->all();
        if (empty($ids)) {
            return collect();
        }

        /** @var Post $post */
        $post = $this->route('post');

        return PostPlatform::query()
            ->where('post_id', $post->id)
            ->whereIn('id', $ids)
            ->pluck('platform', 'id');
    }
}
