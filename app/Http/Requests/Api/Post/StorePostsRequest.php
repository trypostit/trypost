<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Enums\PostPlatform\ContentType;
use App\Rules\PostContentFitsMaxLength;
use App\Support\PostMediaRules;
use App\Support\PostStatusRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->currentWorkspace->id;

        return [
            'status' => ['required', 'string', Rule::in(['draft', 'scheduled', 'publishing'])],
            'content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            ...PostMediaRules::rules(),
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19'],
            'queue' => PostStatusRules::queueRules(),
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspaceId)->withoutTrashed()],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.social_account_id' => [
                'required',
                'uuid',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $workspaceId),
            ],
            'destinations.*.content_type' => ['required', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
            'destinations.*.content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            ...PostMediaRules::rules('destinations.*.media'),
            'destinations.*.meta' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PostStatusRules::queueMessages();
    }
}
