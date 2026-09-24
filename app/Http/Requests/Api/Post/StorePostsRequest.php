<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Enums\PostPlatform\ContentType;
use App\Support\PostMediaRules;
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
            'content' => ['sometimes', 'nullable', 'string', 'max:10000'],
            ...PostMediaRules::rules(hosted: false),
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19'],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspaceId)],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.social_account_id' => [
                'required',
                'uuid',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $workspaceId)->where('is_active', true),
            ],
            'destinations.*.content_type' => ['required', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
            'destinations.*.content' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'destinations.*.media' => ['sometimes', 'array'],
            'destinations.*.meta' => ['sometimes', 'array'],
        ];
    }
}
