<?php

declare(strict_types=1);

namespace App\Http\Requests\App\RssFeed;

use App\Models\RssFeed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRssFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RssFeed::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
            'rss_feed_collection_id' => [
                'nullable',
                'uuid',
                Rule::exists('rss_feed_collections', 'id')->where('workspace_id', $this->user()->current_workspace_id),
            ],
            'source' => ['nullable', 'string', 'in:explore'],
        ];
    }

    public function fromExplore(): bool
    {
        return $this->validated('source') === 'explore';
    }
}
