<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\InboxConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A conversation with one page of its top-level messages, each carrying its
 * reply tree, and the page's position.
 */
class InboxConversationPageResource extends JsonResource
{
    public function __construct(InboxConversation $conversation, private readonly LengthAwarePaginator $threads)
    {
        parent::__construct($conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...InboxConversationResource::make($this->resource)->resolve($request),
            'messages' => InboxMessageResource::collection($this->threads->items())->resolve($request),
            'meta' => [
                'current_page' => $this->threads->currentPage(),
                'last_page' => $this->threads->lastPage(),
                'per_page' => $this->threads->perPage(),
                'total' => $this->threads->total(),
            ],
        ];
    }
}
