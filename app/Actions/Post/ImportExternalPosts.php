<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Post\Origin;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\ContentSanitizer;
use App\Support\PostHistoryRetention;
use App\Support\Social\ThreadProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns an account's newest unlinked publications into published posts with
 * origin `network`, without firing any post event.
 */
class ImportExternalPosts
{
    /**
     * Networks whose publish response can leave a TryPost post on an id the
     * network never reports back: an Instagram container id, a TikTok publish_id.
     */
    private const array PROVISIONAL_ID_PLATFORMS = [Platform::Instagram, Platform::InstagramFacebook, Platform::TikTok];

    private const int MIN_TRUNCATED_MATCH_LENGTH = 20;

    /**
     * @return list<string>
     */
    public static function execute(SocialAccount $account): array
    {
        $limit = (int) config('trypost.external_posts.import_limit');

        if ($limit < 1 || ! $account->platform->isIncludedInAnalytics()) {
            return [];
        }

        $created = [];

        foreach (self::candidates($account, $limit) as $publicationId) {
            try {
                $postId = self::import($account, $publicationId);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if ($postId !== null) {
                $created[] = $postId;
            }
        }

        return $created;
    }

    /**
     * The newest publications up to the limit, counted apart for stories so a
     * day of stories never pushes feed posts and reels out of the import.
     *
     * @return list<string>
     */
    private static function candidates(SocialAccount $account, int $limit): array
    {
        return [
            ...self::newestUnlinked($account, $limit, stories: false),
            ...self::newestUnlinked($account, $limit, stories: true),
        ];
    }

    /**
     * @return list<string>
     */
    private static function newestUnlinked(SocialAccount $account, int $limit, bool $stories): array
    {
        return AnalyticsPublication::query()
            ->available()
            ->where('workspace_id', $account->workspace_id)
            ->where('social_account_id', $account->id)
            ->where('provider_published_at', '>=', now()->subDays(PostHistoryRetention::days()))
            ->when(
                $stories,
                fn (Builder $query): Builder => $query->where('content_type', PublicationContentType::Story),
                fn (Builder $query): Builder => $query->where('content_type', '!=', PublicationContentType::Story),
            )
            ->when($account->platform === Platform::X, fn (Builder $query): Builder => $query
                ->where('provider_published_at', '>=', now()->subDays((int) config('trypost.external_posts.x_import_days'))))
            ->orderByDesc('provider_published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'post_platform_id', 'post_dismissed_at'])
            ->filter(fn (AnalyticsPublication $publication): bool => $publication->post_platform_id === null && $publication->post_dismissed_at === null)
            ->pluck('id')
            ->values()
            ->all();
    }

    private static function import(SocialAccount $account, string $publicationId): ?string
    {
        return DB::transaction(function () use ($account, $publicationId): ?string {
            $publication = AnalyticsPublication::query()->lockForUpdate()->find($publicationId);

            if ($publication === null || $publication->post_platform_id !== null || $publication->post_dismissed_at !== null) {
                return null;
            }

            $existing = self::tryPostTarget($account, $publication);

            if ($existing !== null) {
                if (! AnalyticsPublication::query()->where('post_platform_id', $existing->id)->exists()) {
                    $publication->update(['post_platform_id' => $existing->id]);
                }

                return null;
            }

            if (self::isTryPostThreadSegment($account, $publication)) {
                return null;
            }

            if (self::claimedBySentPost($account, $publication)) {
                return null;
            }

            $postPlatform = Post::withoutEvents(fn (): PostPlatform => self::createPost($account, $publication));
            $publication->update(['post_platform_id' => $postPlatform->id]);

            return $postPlatform->post_id;
        });
    }

    private static function tryPostTarget(SocialAccount $account, AnalyticsPublication $publication): ?PostPlatform
    {
        $remoteIds = collect([
            $publication->remote_id,
            $account->platform === Platform::Facebook ? data_get($publication->provider_metadata, 'video_id') : null,
            $account->platform === Platform::Facebook ? data_get($publication->provider_metadata, 'photo_id') : null,
        ])->filter(fn (mixed $id): bool => is_string($id) && filled($id))->values()->all();

        return PostPlatform::query()
            ->where('social_account_id', $account->id)
            ->whereIn('platform_post_id', $remoteIds)
            ->whereHas('post', fn (Builder $post): Builder => $post->where('workspace_id', $account->workspace_id))
            ->first();
    }

    /**
     * A reply TryPost chained under its own post, or a segment of a thread
     * that stopped midway and is still waiting for its retry.
     */
    private static function isTryPostThreadSegment(SocialAccount $account, AnalyticsPublication $publication): bool
    {
        $remoteId = $publication->remote_id;

        return filled($remoteId) && PostPlatform::query()
            ->where('social_account_id', $account->id)
            ->where(fn (Builder $query): Builder => $query
                ->whereJsonContains('thread_reply_ids', $remoteId)
                ->orWhereJsonContains('error_context->'.ThreadProgress::KEY, [['id' => $remoteId]]))
            ->exists();
    }

    /**
     * A post TryPost published can still carry an id the network never reports
     * back (an Instagram container id, a TikTok publish_id). The one sent post
     * from this channel with the same text near the same time is that
     * publication: it takes over the remote id instead of a duplicate import.
     * More than one such post, or an Instagram post without a caption, is
     * ambiguous, so the publication waits without claiming a remote id.
     */
    private static function claimedBySentPost(SocialAccount $account, AnalyticsPublication $publication): bool
    {
        $targets = self::sentPostsMatching($account, $publication);

        if ($targets->isEmpty()) {
            return false;
        }

        if ($targets->count() > 1 || blank(Str::squish((string) $publication->excerpt))) {
            if (! Cache::add("import-external-posts:ambiguous:{$publication->id}", true, now()->addDay())) {
                return true;
            }

            Log::warning('External publication identity is ambiguous; not imported.', [
                'analytics_publication_id' => $publication->id,
                'post_platform_ids' => $targets->modelKeys(),
            ]);

            return true;
        }

        $target = $targets->sole();

        if ($target->analyticsPublication !== null) {
            app(UpsertAnalyticsPublication::class)->reconcileRemoteId($target->analyticsPublication, $publication->remote_id);

            return true;
        }

        $publication->update(['post_platform_id' => $target->id]);
        PostPlatform::query()->whereKey($target->id)->update([
            'platform_post_id' => $publication->remote_id,
            'platform_url' => $publication->permalink ?? $target->platform_url,
        ]);

        return true;
    }

    /**
     * @return Collection<int, PostPlatform>
     */
    private static function sentPostsMatching(SocialAccount $account, AnalyticsPublication $publication): Collection
    {
        $window = (int) config('trypost.external_posts.match_window_minutes');
        $text = Str::squish((string) $publication->excerpt);

        if ($window < 1 || ! in_array($account->platform, self::PROVISIONAL_ID_PLATFORMS, true)) {
            return new Collection;
        }

        $publishedAt = $publication->provider_published_at->toImmutable();

        return PostPlatform::query()
            ->where('social_account_id', $account->id)
            ->enabled()
            ->published()
            ->whereIn('content_type', self::compatibleContentTypes(ContentType::fromPublication($account->platform, $publication->content_type)))
            ->whereBetween('published_at', [$publishedAt->subMinutes($window), $publishedAt->addMinutes($window)])
            ->whereHas('post', fn (Builder $post): Builder => $post->createdInTryPost()->where('workspace_id', $account->workspace_id))
            ->where(fn (Builder $query): Builder => $query
                ->whereDoesntHave('analyticsPublication')
                ->orWhereHas('analyticsPublication', fn (Builder $linked): Builder => $linked
                    ->where('origin', PublicationOrigin::TryPost)
                    ->whereNull('provider_synced_at')
                    ->whereColumn('analytics_publications.remote_id', 'post_platforms.platform_post_id')))
            ->with(['post:id,content', 'analyticsPublication'])
            ->get()
            ->filter(fn (PostPlatform $target): bool => self::sameText(
                $target->content_type->isCaptionless() ? '' : (string) $target->post?->content,
                $text,
                $account->platform,
            ))
            ->values();
    }

    /**
     * Instagram reports a feed video TryPost published as a reel, so feed and
     * reel stand in for each other; a story only ever matches a story.
     * TikTok's video/list also returns photo posts without a media type.
     *
     * @return list<ContentType>
     */
    private static function compatibleContentTypes(ContentType $type): array
    {
        return match ($type) {
            ContentType::InstagramFeed, ContentType::InstagramReel => [ContentType::InstagramFeed, ContentType::InstagramReel],
            ContentType::TikTokVideo => [ContentType::TikTokVideo, ContentType::TikTokPhoto],
            default => [$type],
        };
    }

    /**
     * Equal text, or a network excerpt that visibly ends in an ellipsis and
     * still carries enough of the caption to tell posts apart.
     * Empty Instagram captions are candidates for deferral only.
     */
    private static function sameText(string $content, string $text, Platform $platform): bool
    {
        $sent = Str::squish(app(ContentSanitizer::class)->displayText($content, $platform));

        if (blank($sent)) {
            return blank($text) && in_array($platform, [Platform::Instagram, Platform::InstagramFacebook], true);
        }

        if ($sent === $text) {
            return true;
        }

        $truncated = (string) Str::of($text)->chopEnd(['...', '…'])->trim();

        return $truncated !== $text
            && Str::length($truncated) >= self::MIN_TRUNCATED_MATCH_LENGTH
            && Str::startsWith($sent, $truncated);
    }

    private static function createPost(SocialAccount $account, AnalyticsPublication $publication): PostPlatform
    {
        $publishedAt = $publication->provider_published_at;

        $post = Post::query()->create([
            'workspace_id' => $account->workspace_id,
            'post_group_id' => (string) Str::uuid7(),
            'user_id' => null,
            'content' => trim((string) $publication->excerpt),
            'media' => [],
            'status' => PostStatus::Published,
            'origin' => Origin::Network,
            'published_at' => $publishedAt,
        ]);

        return $post->postPlatforms()->create([
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            ...$account->channelSnapshot(),
            'content_type' => ContentType::fromPublication($account->platform, $publication->content_type),
            'status' => PostPlatformStatus::Published,
            'enabled' => true,
            'platform_post_id' => $publication->remote_id,
            'platform_url' => $publication->permalink,
            'published_at' => $publishedAt,
            'meta' => [],
        ]);
    }
}
