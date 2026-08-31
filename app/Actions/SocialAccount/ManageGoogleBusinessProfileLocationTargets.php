<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use Illuminate\Support\Collection;

class ManageGoogleBusinessProfileLocationTargets
{
    private const REASON = 'gbp_location_disconnected';

    /** @param Collection<int, string>|array<int, string> $locationIds */
    public function disable(Collection|array $locationIds): int
    {
        $ids = collect($locationIds)->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        $targets = PostPlatform::query()
            ->whereIn('google_business_profile_location_id', $ids)
            ->where('status', PostPlatformStatus::Pending)
            ->where('enabled', true)
            ->whereHas('post', fn ($query) => $query->whereIn('status', [PostStatus::Draft, PostStatus::Scheduled]))
            ->lockForUpdate()
            ->get();

        $affectedPostIds = $targets->pluck('post_id')->unique();

        foreach ($targets as $target) {
            $target->update([
                'enabled' => false,
                'error_message' => __('posts.errors.gbp_location_disconnected'),
                'error_context' => [
                    ...(is_array($target->error_context) ? $target->error_context : []),
                    'category' => 'connection_action_required',
                    'reason' => self::REASON,
                    'disconnected_at' => now()->toIso8601String(),
                ],
            ]);
        }

        // A scheduled post is one publish unit. If any destination is removed,
        // stop the whole schedule so it cannot partially publish elsewhere.
        Post::query()
            ->whereIn('id', $affectedPostIds)
            ->where('status', PostStatus::Scheduled)
            ->update(['status' => PostStatus::Draft, 'scheduled_at' => null]);

        return $targets->count();
    }

    /** @param Collection<int, string>|array<int, string> $locationIds */
    public function restore(Collection|array $locationIds): int
    {
        $ids = collect($locationIds)->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        $targets = PostPlatform::query()
            ->whereIn('google_business_profile_location_id', $ids)
            ->where('status', PostPlatformStatus::Pending)
            ->where('enabled', false)
            ->whereHas('post', fn ($query) => $query->where('status', PostStatus::Draft))
            ->lockForUpdate()
            ->get()
            ->filter(fn (PostPlatform $target): bool => data_get($target->error_context, 'reason') === self::REASON);

        foreach ($targets as $target) {
            $target->update([
                'enabled' => true,
                'error_message' => null,
                'error_context' => null,
            ]);
        }

        return $targets->count();
    }
}
