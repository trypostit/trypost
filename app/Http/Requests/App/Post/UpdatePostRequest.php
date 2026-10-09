<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Rules\ContentFitsPlatformLimits;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Rules\ContentTypeMatchesPostChannel;
use App\Rules\PostContentFitsMaxLength;
use App\Support\PostMediaRules;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A tab opened before posts and their destinations were merged still
     * sends `platforms[]`; reload it instead of dropping the edit.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('platforms')) {
            throw new HttpResponseException(Inertia::location(url()->previous()));
        }
    }

    public function rules(): array
    {
        $post = $this->route('post');
        $status = $this->input('status');

        $enforcesMediaCompatibility = in_array(
            $status,
            [Status::Scheduled->value, Status::Publishing->value],
            true,
        );
        $target = $post->socialAccount ?? $post->platform;

        return [
            'status' => ['required', 'string', Rule::in([Status::Draft->value, Status::Scheduled->value, Status::Publishing->value])],
            'content' => [
                'nullable',
                'string',
                new PostContentFitsMaxLength,
                Rule::when(
                    $enforcesMediaCompatibility,
                    [new ContentFitsPlatformLimits(
                        collect(filled($target) ? ['post' => $target] : []),
                        ['post' => $this->effectiveMeta()],
                        ['post' => $this->input('content_type') ?? $post->content_type?->value],
                    )]
                ),
            ],
            ...PostMediaRules::hostedRules(),
            'scheduled_at' => PostStatusRules::scheduledAtRules($post, $status, $this->filled('queue')),
            'queue' => PostStatusRules::queueRules(),
            'social_account_id' => ['prohibited'],
            'content_type' => [
                'sometimes',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
                new ContentTypeMatchesPostChannel($post),
                Rule::when($enforcesMediaCompatibility, [new ContentTypeCompatibleWithMedia(workspace: $post->workspace)]),
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
            $post = $this->route('post');

            if (! $this->isPublishingOrScheduling() || ! $post->hasDestination()) {
                return;
            }

            PostPlatformMetaRules::addRequiredOnPublishErrors($validator, $post->socialAccount ?? $post->platform, $this->effectiveMeta());
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
     * The meta the save would store: the submitted keys over the stored ones.
     *
     * @return array<string, mixed>
     */
    private function effectiveMeta(): array
    {
        return [...($this->route('post')->meta ?? []), ...(array) $this->input('meta', [])];
    }
}
