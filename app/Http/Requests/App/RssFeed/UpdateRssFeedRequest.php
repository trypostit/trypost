<?php

declare(strict_types=1);

namespace App\Http\Requests\App\RssFeed;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRssFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('rssFeed'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'custom_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rss_feed_collection_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('rss_feed_collections', 'id')->where('workspace_id', $this->user()->current_workspace_id),
            ],
        ];
    }
}
