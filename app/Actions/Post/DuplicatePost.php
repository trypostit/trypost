<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Duplicate one account's version into an independent draft.
 */
class DuplicatePost
{
    public static function execute(Post $original, User $user, ?string $targetId = null): Post
    {
        return DB::transaction(function () use ($original, $user, $targetId): Post {
            $targets = $original->postPlatforms()
                ->enabled()
                ->whereHas('socialAccount', fn ($query) => $query->where('is_active', true))
                ->get();
            $target = $targetId === null && $targets->count() === 1
                ? $targets->first()
                : $targets->firstWhere('id', $targetId);

            if ($target === null) {
                throw ValidationException::withMessages([
                    'post_platform_id' => __('validation.exists', ['attribute' => 'post platform']),
                ]);
            }

            return CreateChannelPost::execute($original->workspace, $user, [
                'content' => $original->content,
                'media' => $original->media ?? [],
                'status' => PostStatus::Draft->value,
                'created_via' => CreatedVia::Web,
                'social_account_id' => $target->social_account_id,
                'content_type' => $target->content_type->value,
                'meta' => $target->meta ?? [],
                'label_ids' => $original->labels()->pluck('workspace_labels.id')->all(),
            ]);
        });
    }
}
