<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Post\Origin;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\ContentSanitizer;
use App\Support\PostHistoryRetention;
use App\Support\Social\PublishCheckpoint;
use App\Support\Social\ThreadProgress;
use Carbon\CarbonImmutable;
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
            ->get(['id', 'post_id', 'post_dismissed_at'])
            ->filter(fn (AnalyticsPublication $publication): bool => blank($publication->post_id) && blank($publication->post_dismissed_at))
            ->pluck('id')
            ->values()
            ->all();
    }

    private static function import(SocialAccount $account, string $publicationId): ?string
    {
        return DB::transaction(function () use ($account, $publicationId): ?string {
            $publication = AnalyticsPublication::query()->lockForUpdate()->find($publicationId);

            if (blank($publication) || filled($publication->post_id) || filled($publication->post_dismissed_at)) {
                return null;
            }

            $existing = self::tryPostTarget($account, $publication);

            if ($existing !== null) {
                if (! AnalyticsPublication::query()->where('post_id', $existing->id)->exists()) {
                    $publication->update(['post_id' => $existing->id]);
                }

                return null;
            }

            if (self::isTryPostThreadSegment($account, $publication)) {
                return null;
            }

            if (self::claimedBySentPost($account, $publication)) {
                return null;
            }

            $post = Post::withoutEvents(fn (): Post => self::createPost($account, $publication));
            $publication->update(['post_id' => $post->id]);

            return $post->id;
        });
    }

    private static function tryPostTarget(SocialAccount $account, AnalyticsPublication $publication): ?Post
    {
        $remoteIds = collect([
            $publication->remote_id,
            $account->platform === Platform::Facebook ? data_get($publication->provider_metadata, 'video_id') : null,
            $account->platform === Platform::Facebook ? data_get($publication->provider_metadata, 'photo_id') : null,
        ])->filter(fn (mixed $id): bool => is_string($id) && filled($id))->values()->all();

        return Post::query()
            ->where('social_account_id', $account->id)
            ->whereIn('platform_post_id', $remoteIds)
            ->where('workspace_id', $account->workspace_id)
            ->first();
    }

    /**
     * A reply TryPost chained under its own post, or a segment of a thread
     * that stopped midway and is still waiting for its retry.
     */
    private static function isTryPostThreadSegment(SocialAccount $account, AnalyticsPublication $publication): bool
    {
        $remoteId = $publication->remote_id;

        return filled($remoteId) && Post::query()
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
     * Multiple candidates, captionless Instagram posts and Instagram publishes
     * still in progress wait without claiming a remote id. A TikTok post whose
     * numeric video id TikTok already reported is never a candidate.
     */
    private static function claimedBySentPost(SocialAccount $account, AnalyticsPublication $publication): bool
    {
        $targets = self::matchingTryPostTargets($account, $publication);

        if ($targets->isEmpty()) {
            return false;
        }

        if ($targets->count() > 1
            || blank(Str::squish((string) $publication->excerpt))
            || $targets->contains(fn (Post $target): bool => $target->publish_status !== PublishStatus::Published)) {
            if (! Cache::add("import-external-posts:ambiguous:{$publication->id}", true, now()->addDay())) {
                return true;
            }

            Log::warning('External publication identity is ambiguous; not imported.', [
                'analytics_publication_id' => $publication->id,
                'post_ids' => $targets->modelKeys(),
            ]);

            return true;
        }

        $target = $targets->sole();

        if ($target->analyticsPublication !== null) {
            app(UpsertAnalyticsPublication::class)->reconcileRemoteId($target->analyticsPublication, $publication->remote_id);

            return true;
        }

        $publication->update(['post_id' => $target->id]);
        Post::query()->whereKey($target->id)->toBase()->update([
            'platform_post_id' => $publication->remote_id,
            'platform_url' => $publication->permalink ?? $target->platform_url,
            'publication_updated_at' => now(),
        ]);

        return true;
    }

    /**
     * @return Collection<int, Post>
     */
    private static function matchingTryPostTargets(SocialAccount $account, AnalyticsPublication $publication): Collection
    {
        $window = (int) config('trypost.external_posts.match_window_minutes');
        $text = Str::squish((string) $publication->excerpt);

        if ($window < 1 || ! in_array($account->platform, self::PROVISIONAL_ID_PLATFORMS, true)) {
            return new Collection;
        }

        $publishedAt = $publication->provider_published_at->toImmutable();

        return Post::query()
            ->where('social_account_id', $account->id)
            ->where(fn (Builder $query): Builder => self::matchingPublicationState($query, $account->platform, $publishedAt, $window))
            ->whereIn('content_type', self::compatibleContentTypes(ContentType::fromPublication($account->platform, $publication->content_type)))
            ->createdInTryPost()
            ->where('workspace_id', $account->workspace_id)
            ->where(fn (Builder $query): Builder => $query
                ->whereDoesntHave('analyticsPublication')
                ->orWhereHas('analyticsPublication', fn (Builder $linked): Builder => $linked
                    ->where('origin', PublicationOrigin::TryPost)
                    ->whereNull('provider_synced_at')
                    ->whereColumn('analytics_publications.remote_id', 'posts.platform_post_id')))
            ->with('analyticsPublication')
            ->get()
            ->reject(fn (Post $target): bool => $account->platform === Platform::TikTok && ctype_digit((string) $target->platform_post_id))
            ->filter(fn (Post $target): bool => self::sameText(
                $target->content_type->isCaptionless() ? '' : (string) $target->content,
                $text,
                $account->platform,
            ))
            ->values();
    }

    private static function matchingPublicationState(Builder $query, Platform $platform, CarbonImmutable $publishedAt, int $window): Builder
    {
        $interval = [$publishedAt->subMinutes($window), $publishedAt->addMinutes($window)];

        $query->where(fn (Builder $sent): Builder => $sent->publicationPublished()->whereBetween('published_at', $interval));

        if (! in_array($platform, [Platform::Instagram, Platform::InstagramFacebook], true)) {
            return $query;
        }

        return $query->orWhere(fn (Builder $pending): Builder => $pending
            ->whereBetween('publication_updated_at', $interval)
            ->where(fn (Builder $state): Builder => $state
                ->where('publish_status', PublishStatus::Publishing)
                ->orWhere(fn (Builder $retry): Builder => $retry
                    ->where('publish_status', PublishStatus::Retrying)
                    ->whereNotNull('error_context->'.PublishCheckpoint::INSTAGRAM_WORKFLOW.'->container_id'))));
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

    private static function createPost(SocialAccount $account, AnalyticsPublication $publication): Post
    {
        return Post::query()->forceCreate([
            'workspace_id' => $account->workspace_id,
            'post_group_id' => (string) Str::uuid7(),
            'user_id' => null,
            'content' => trim((string) $publication->excerpt),
            'media' => [],
            'status' => PostStatus::Published,
            'origin' => Origin::Network,
            'published_at' => $publication->provider_published_at,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            ...$account->channelSnapshot(),
            'content_type' => ContentType::fromPublication($account->platform, $publication->content_type),
            'publish_status' => PublishStatus::Published,
            'platform_post_id' => $publication->remote_id,
            'platform_url' => $publication->permalink,
            'meta' => [],
        ]);
    }
}
