<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Shared data for the approval emails: the posts of one request (one per
 * channel, same post_group_id), their channels, an excerpt and a local time.
 */
class ApprovalEmailPosts
{
    /**
     * @param  list<string>  $postIds
     * @return Collection<int, Post>
     */
    public static function load(array $postIds): Collection
    {
        return Post::query()
            ->with(['workspace', 'postPlatforms' => fn ($platforms) => $platforms->enabled()->with('socialAccount')])
            ->whereIn('id', $postIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public static function excerpt(?Post $post): string
    {
        return PostExcerpt::from($post?->content, 280);
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<PostPlatform>
     */
    public static function channels(Collection $posts): array
    {
        return $posts->flatMap(fn (Post $post) => $post->postPlatforms)
            ->unique(fn (PostPlatform $postPlatform): string => $postPlatform->social_account_id ?? $postPlatform->id)
            ->values()
            ->all();
    }

    /**
     * When each channel goes out once approved: a null time means it is
     * publishing now. One shared moment collapses into a single line.
     *
     * @param  Collection<int, Post>  $posts
     * @return array{at: ?string, perChannel: list<array{channels: list<PostPlatform>, at: ?string}>}
     */
    public static function goesOut(Collection $posts, User $recipient): array
    {
        $perChannel = $posts->map(fn (Post $post): array => [
            'channels' => self::channels(collect([$post])),
            'at' => $post->status === PostStatus::Scheduled ? self::time($post->scheduled_at, $recipient) : null,
        ]);

        if ($perChannel->pluck('at')->unique()->count() <= 1) {
            return ['at' => data_get($perChannel->first(), 'at'), 'perChannel' => []];
        }

        return ['at' => null, 'perChannel' => $perChannel->values()->all()];
    }

    public static function time(?CarbonInterface $at, User $recipient): ?string
    {
        if ($at === null) {
            return null;
        }

        $dateTime = RecipientTime::dateTime($at, $recipient);
        $timezone = RecipientTime::timezone($recipient);

        return "{$dateTime} ({$timezone})";
    }
}
