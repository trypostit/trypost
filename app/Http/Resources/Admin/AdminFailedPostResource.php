<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Actions\Post\RetryFailedPost;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Models\User;
use App\Services\Social\ContentSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Post
 */
class AdminFailedPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $retry = app(RetryFailedPost::class);
        $owner = $this->workspace?->account?->owner;

        return [
            'id' => $this->id,
            'platform' => $this->platform?->value,
            'channel_name' => $this->display_name,
            'workspace_name' => $this->workspace?->name,
            'owner_id' => $owner?->id,
            'owner_name' => $owner?->name,
            'channel_status' => $this->socialAccount?->status->value,
            'content_type' => $this->content_type?->value,
            'content_type_label' => $this->content_type?->label(),
            'created_via' => $this->created_via?->value,
            'content' => app(ContentSanitizer::class)->plainText((string) $this->content),
            'media' => collect($this->media ?? [])->map(fn (array $item): array => [
                'url' => data_get($item, 'url'),
                'type' => data_get($item, 'type'),
                'mime_type' => data_get($item, 'mime_type'),
            ])->values()->all(),
            'error_message' => $this->error_message,
            'error_category' => ErrorCategory::tryFromContext($this->error_context)?->value ?? ErrorCategory::Unknown->value,
            'error_context' => $this->error_context,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'failed_at' => $this->publication_updated_at?->toIso8601String(),
            'retryable' => $this->retryable($retry, $owner),
            'needs_instagram_confirmation' => $retry->needsInstagramConfirmation($this->resource),
        ];
    }

    /**
     * A blocked owner's posts wait until they are unblocked, so the
     * admin offers no retry for them.
     */
    private function retryable(RetryFailedPost $retry, ?User $owner): bool
    {
        if ($owner?->isBlocked() === true) {
            return false;
        }

        return $retry->isRetryable($this->resource);
    }
}
