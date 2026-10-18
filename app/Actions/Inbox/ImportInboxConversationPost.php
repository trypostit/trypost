<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Actions\Post\ImportExternalPosts;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Exceptions\Inbox\InboxPostUnavailableException;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\FindsPublication;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;

/**
 * Puts the post a comment is under into TryPost: the channel's post when it
 * is already there, otherwise its publication (found, or created once by its
 * unique account key and remote id) imported like the analytics sync does.
 */
class ImportInboxConversationPost
{
    /**
     * @return string the post's id
     *
     * @throws InboxPostUnavailableException
     */
    public static function execute(SocialAccount $account, IncomingSubject $subject): string
    {
        $remoteId = LinkInboxConversationPost::remoteIdOf($account, $subject);
        $existing = self::post($account, $remoteId);

        if (filled($existing)) {
            return $existing;
        }

        $publication = self::publication($account, $remoteId, $subject);

        if (filled($publication->post_dismissed_at) && blank($publication->post_id)) {
            throw InboxPostUnavailableException::dismissed($remoteId);
        }

        $created = ImportExternalPosts::importPublication($account, $publication->id);

        if (filled($created)) {
            ImportExternalPostMedia::dispatch($created);
        }

        $postId = $created ?? AnalyticsPublication::query()->whereKey($publication->id)->value('post_id') ?? self::post($account, $remoteId);

        if (blank($postId)) {
            throw new InboxPostUnavailableException("The post {$remoteId} could not be imported yet.");
        }

        return (string) $postId;
    }

    private static function post(SocialAccount $account, string $remoteId): ?string
    {
        return Post::query()
            ->where('social_account_id', $account->id)
            ->where('platform_post_id', $remoteId)
            ->value('id');
    }

    private static function publication(SocialAccount $account, string $remoteId, IncomingSubject $subject): AnalyticsPublication
    {
        $existing = AnalyticsPublication::query()
            ->where('social_account_key', app(ResolveAnalyticsAccountKey::class)->for($account))
            ->where('remote_id', $remoteId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $discovered = self::discover($account, $remoteId, $subject);

        if (! $discovered) {
            throw new InboxPostUnavailableException("The network did not return the post {$remoteId}.");
        }

        return app(UpsertAnalyticsPublication::class)->external($account, $discovered);
    }

    /**
     * What the poll listed, else the network read by id, else what the
     * comment's own read told about the post (a network without a single
     * read: the analytics sync fills the type later).
     */
    private static function discover(SocialAccount $account, string $remoteId, IncomingSubject $subject): ?DiscoveredPublication
    {
        if ($subject->publication && $subject->publication->providerPostId === $remoteId) {
            return $subject->publication;
        }

        $collectors = app(PublicationHistoryCollectorFactory::class);
        $collector = $collectors->supports($account->platform) ? $collectors->for($account) : null;

        if ($collector instanceof FindsPublication) {
            try {
                return $collector->find($account, $remoteId);
            } catch (AnalyticsCollectionException $exception) {
                throw new InboxPostUnavailableException("The network did not return the post {$remoteId} ({$exception->category}).", previous: $exception);
            }
        }

        if (blank($subject->publishedAt)) {
            return null;
        }

        $thumbnail = data_get($subject->media, '0.thumbnail_url') ?? data_get($subject->media, '0.url');

        return new DiscoveredPublication(
            providerPostId: $remoteId,
            publishedAt: $subject->publishedAt,
            contentType: PublicationContentType::Unknown,
            permalink: $subject->url,
            excerpt: $subject->text,
            previewMetadata: filled($thumbnail) ? ['thumbnail_url' => (string) $thumbnail] : null,
            contentTypeReported: false,
        );
    }
}
