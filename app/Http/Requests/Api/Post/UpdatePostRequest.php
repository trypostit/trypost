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
        $enforcesPlatformLimits = in_array(
            $this->input('status'),
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
            'platforms.*.id' => ['required', 'uuid', Rule::exists('post_platforms', 'id')->where('post_id', $this->route('post') instanceof Post ? $this->route('post')->id : $this->route('post'))],
            'platforms.*.content_type' => [
                'sometimes',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
                new ContentTypeMatchesPostPlatform,
            ],
            ...PostPlatformMetaRules::rules(),
            'scheduled_at' => [
                Rule::requiredIf($this->input('status') === Status::Scheduled->value),
                'nullable',
                'date',
                Rule::when(
                    $this->input('status') === Status::Scheduled->value,
                    ['after:now']
                ),
            ],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $this->user()->currentWorkspace->id)],
        ];
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
        $routePost = $this->route('post');
        $post = $routePost instanceof Post ? $routePost : Post::find($routePost);

        if (! $post) {
            return;
        }

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

        $post = $this->route('post');
        $postId = $post instanceof Post ? $post->id : $post;

        return PostPlatform::query()
            ->where('post_id', $postId)
            ->whereIn('id', $ids)
            ->pluck('platform', 'id');
    }
}
