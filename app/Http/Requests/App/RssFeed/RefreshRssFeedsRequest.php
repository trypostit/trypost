<?php

declare(strict_types=1);

namespace App\Http\Requests\App\RssFeed;

use App\Models\RssFeed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefreshRssFeedsRequest extends FormRequest
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
        $workspaceId = $this->user()->current_workspace_id;

        return [
            'rss_feed_id' => ['nullable', 'uuid', Rule::exists('rss_feeds', 'id')->where('workspace_id', $workspaceId)],
            'rss_feed_collection_id' => ['nullable', 'uuid', Rule::exists('rss_feed_collections', 'id')->where('workspace_id', $workspaceId)],
        ];
    }
}
