<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Media\DeleteOwnedMedia;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Analytics\PublicationAvailability;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Post\PublishStatus;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Analytics\SyncCadence;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;

class UpsertAnalyticsPublication
{
    private const int EXCERPT_MAX_BYTES = 65535;

    /**
     * `provider_metadata` keys only the metrics read writes (the polls of X
     * thread replies, which discovery never sees).
     *
     * @var list<string>
     */
    private const array METRICS_ONLY_PROVIDER_METADATA = ['thread_polls'];

    public function __construct(
        private readonly ResolveAnalyticsAccountKey $accountKeys,
        private readonly WritePublicationDailySnapshot $snapshots,
    ) {}

    public function external(
        SocialAccount $account,
        DiscoveredPublication $publication,
        ?TryPostPublicationIdentity $identity = null,
    ): AnalyticsPublication {
        $identity ??= TryPostPublicationIdentity::fromAccount(
            $account,
            $this->accountKeys->for($account),
        );

        return $this->persist(
            identity: $identity,
            providerPostId: $publication->providerPostId,
            providerPublishedAt: $publication->publishedAt,
            origin: PublicationOrigin::External,
            contentType: $publication->contentType,
            providerContentType: $publication->providerContentType,
            permalink: $publication->permalink,
            excerpt: $publication->excerpt,
            previewMetadata: $publication->previewMetadata,
            providerMetadata: $publication->providerMetadata,
            providerSyncedAt: now(),
            liveAccount: $account,
            contentTypeReported: $publication->contentTypeReported,
        );
    }

    public function tryPost(
        TryPostPublicationIdentity $identity,
        Post $post,
        PublicationContentType $contentType,
        ?string $excerpt,
        ?SocialAccount $liveAccount = null,
    ): AnalyticsPublication {
        return $this->persist(
            identity: $identity,
            providerPostId: (string) $post->platform_post_id,
            providerPublishedAt: ($post->published_at ?? $post->publication_updated_at ?? $post->updated_at)->toImmutable(),
            origin: PublicationOrigin::TryPost,
            contentType: $contentType,
            providerContentType: $post->content_type->value,
            permalink: $post->platform_url,
            excerpt: $excerpt,
            previewMetadata: null,
            providerMetadata: null,
            postId: $post->id,
            liveAccount: $liveAccount,
        );
    }

    /**
     * Moves a TryPost publication to the id the network reports, merging the
     * discovery already stored under it, and moves the post to that id too.
     */
    public function reconcileRemoteId(AnalyticsPublication $publication, string $remoteId): void
    {
        $this->moveToRemoteId($publication, $remoteId, movePost: true);
    }

    /**
     * Merges the discovery stored under `$remoteId` into a TryPost publication
     * that the network reports under another id, while the post keeps the id it
     * was published under: a Facebook video or reel is known by its video id to
     * the post (repurpose skips what TryPost published by that id) and by its
     * Page post id to discovery. Only while the publication still sits on the
     * post's own id, checked under its lock. False when nothing was merged.
     */
    public function mergeDiscovery(AnalyticsPublication $publication, string $remoteId): bool
    {
        return $this->moveToRemoteId($publication, $remoteId, movePost: false);
    }

    private function moveToRemoteId(AnalyticsPublication $publication, string $remoteId, bool $movePost): bool
    {
        return DB::transaction(function () use ($movePost, $publication, $remoteId): bool {
            $current = AnalyticsPublication::query()->lockForUpdate()->findOrFail($publication->id);

            if ($current->remote_id === $remoteId || ! $current->post_id) {
                return false;
            }

            if (! $movePost && Post::query()->whereKey($current->post_id)->value('platform_post_id') !== $current->remote_id) {
                return false;
            }

            $discovered = AnalyticsPublication::query()
                ->where('workspace_id', $current->workspace_id)
                ->where('social_account_key', $current->social_account_key)
                ->where('network', $current->network)
                ->where('remote_id', $remoteId)
                ->lockForUpdate()
                ->first();

            if (! $movePost && blank($discovered)) {
                return false;
            }

            if ($discovered) {
                if ($discovered->post_id && $discovered->post_id !== $current->post_id) {
                    $this->deleteImportedPost($discovered->post_id);
                }

                $discovered->dailySnapshots()->lockForUpdate()->reorder()->lazyById(100)->each(function (AnalyticsPublicationDailySnapshot $snapshot) use ($current): void {
                    $existing = $current->dailySnapshots()
                        ->where('date', $snapshot->date->toDateString())
                        ->lockForUpdate()
                        ->first();

                    if (! $existing) {
                        $snapshot->update(['publication_id' => $current->id]);

                        return;
                    }

                    $this->snapshots->combine($existing, $snapshot);

                    $snapshot->delete();
                });

                $current->fill([
                    'permalink' => $discovered->permalink ?? $current->permalink,
                    'preview_metadata' => $discovered->preview_metadata ?? $current->preview_metadata,
                    'provider_metadata' => self::providerMetadataAfterDiscovery($current, $discovered->provider_metadata),
                    'provider_synced_at' => $discovered->provider_synced_at ?? $current->provider_synced_at,
                    'excerpt' => $discovered->excerpt ?? $current->excerpt,
                    'provider_published_at' => $discovered->provider_published_at ?? $current->provider_published_at,
                    'last_seen_at' => collect([$current->last_seen_at, $discovered->last_seen_at])->filter()->max(),
                    ...($movePost ? [] : [
                        'content_type' => $discovered->content_type,
                        'provider_content_type' => $discovered->provider_content_type ?? $current->provider_content_type,
                    ]),
                ]);
                $discovered->delete();
            }

            $current->remote_id = $remoteId;
            $current->save();

            if ($movePost) {
                Post::query()->whereKey($current->post_id)->toBase()->update([
                    'platform_post_id' => $remoteId,
                    'platform_url' => $current->permalink,
                    'publication_updated_at' => now(),
                ]);
            }

            $publication->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }

    private function deleteImportedPost(string $postId): void
    {
        $post = Post::query()
            ->imported()
            ->whereKey($postId)
            ->lockForUpdate()
            ->first();

        if ($post === null) {
            throw new LogicException('The remote id is already attached to another TryPost publication.');
        }

        DeleteOwnedMedia::forPosts([$post->id]);
        Post::withoutEvents(fn (): ?bool => $post->delete());
    }

    /**
     * @param  array<string, mixed>|null  $previewMetadata
     * @param  array<string, mixed>|null  $providerMetadata
     */
    private function persist(
        TryPostPublicationIdentity $identity,
        string $providerPostId,
        DateTimeInterface $providerPublishedAt,
        PublicationOrigin $origin,
        PublicationContentType $contentType,
        ?string $providerContentType,
        ?string $permalink,
        ?string $excerpt,
        ?array $previewMetadata,
        ?array $providerMetadata,
        ?string $postId = null,
        ?DateTimeInterface $providerSyncedAt = null,
        ?SocialAccount $liveAccount = null,
        bool $contentTypeReported = true,
    ): AnalyticsPublication {
        $excerpt = $excerpt === null ? null : mb_strcut($excerpt, 0, self::EXCERPT_MAX_BYTES, 'UTF-8');

        try {
            return $this->write(
                $identity,
                $providerPostId,
                $providerPublishedAt,
                $origin,
                $contentType,
                $providerContentType,
                $permalink,
                $excerpt,
                $previewMetadata,
                $providerMetadata,
                $postId,
                $providerSyncedAt,
                $liveAccount,
                true,
                $contentTypeReported,
            );
        } catch (UniqueConstraintViolationException) {
            return $this->write(
                $identity,
                $providerPostId,
                $providerPublishedAt,
                $origin,
                $contentType,
                $providerContentType,
                $permalink,
                $excerpt,
                $previewMetadata,
                $providerMetadata,
                $postId,
                $providerSyncedAt,
                $liveAccount,
                false,
                $contentTypeReported,
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $previewMetadata
     * @param  array<string, mixed>|null  $providerMetadata
     */
    private function write(
        TryPostPublicationIdentity $identity,
        string $providerPostId,
        DateTimeInterface $providerPublishedAt,
        PublicationOrigin $origin,
        PublicationContentType $contentType,
        ?string $providerContentType,
        ?string $permalink,
        ?string $excerpt,
        ?array $previewMetadata,
        ?array $providerMetadata,
        ?string $postId,
        ?DateTimeInterface $providerSyncedAt,
        ?SocialAccount $liveAccount,
        bool $mayCreate,
        bool $contentTypeReported,
    ): AnalyticsPublication {
        return DB::transaction(function () use ($contentType, $contentTypeReported, $excerpt, $identity, $liveAccount, $mayCreate, $origin, $permalink, $postId, $previewMetadata, $providerContentType, $providerMetadata, $providerPostId, $providerPublishedAt, $providerSyncedAt): AnalyticsPublication {
            $identityFields = [
                'workspace_id' => $identity->workspaceId,
                'social_account_key' => $identity->socialAccountKey,
                'network' => $identity->network,
                'remote_id' => $providerPostId,
            ];
            $publication = AnalyticsPublication::query()
                ->where($identityFields)
                ->lockForUpdate()
                ->first();

            if (! $publication && $postId) {
                $publication = AnalyticsPublication::query()
                    ->where('post_id', $postId)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $publication && ! $mayCreate) {
                $publication = AnalyticsPublication::query()
                    ->where($identityFields)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $now = now();
            $publication ??= new AnalyticsPublication([
                ...$identityFields,
                'first_seen_at' => $now,
            ]);
            $keepsDiscovery = self::keepsDiscovery($publication, $origin, $providerPostId);

            $values = [
                'social_account_id' => $liveAccount?->id
                    ?? SocialAccount::query()->whereKey($identity->socialAccountId)->value('id'),
                'post_id' => $postId ?? $publication->post_id,
                'platform_user_id' => $identity->platformUserId,
                'platform' => $identity->platform,
                'provider_published_at' => $keepsDiscovery ? $publication->provider_published_at : $providerPublishedAt,
                'origin' => $origin === PublicationOrigin::TryPost
                    ? PublicationOrigin::TryPost
                    : ($publication->origin ?? PublicationOrigin::External),
                'content_type' => ($contentTypeReported || ! $publication->exists) && ! $keepsDiscovery ? $contentType : $publication->content_type,
                'availability' => PublicationAvailability::Available,
                'last_seen_at' => $now,
                'provider_synced_at' => $providerSyncedAt ?? $publication->provider_synced_at,
            ];

            foreach ([
                'provider_content_type' => $keepsDiscovery ? null : $providerContentType,
                'permalink' => $keepsDiscovery ? null : $permalink,
                'excerpt' => $keepsDiscovery ? null : $excerpt,
                'preview_metadata' => $previewMetadata,
                'provider_metadata' => self::providerMetadataAfterDiscovery($publication, $providerMetadata),
                'account_display_name' => $identity->accountDisplayName,
                'account_username' => $identity->accountUsername,
                'account_avatar_url' => $identity->accountAvatarUrl,
            ] as $key => $value) {
                if ($value !== null) {
                    $values[$key] = $value;
                }
            }

            $wasDeleted = $publication->availability === PublicationAvailability::Deleted;
            $publication->fill($values)->save();

            if ($wasDeleted && $publication->post?->publish_status === PublishStatus::Deleted) {
                $publication->post->markPublicationRestored();
                $this->readAgain($publication);
            }

            return $publication->refresh();
        });
    }

    /**
     * A TryPost write that found its publication under the Page post carrying
     * the post's Facebook video or photo (the post kept on its media id, the
     * publication merged under its Page post id) keeps what discovery reported
     * about that Page post: its link, time, type and text.
     */
    private static function keepsDiscovery(AnalyticsPublication $publication, PublicationOrigin $origin, string $providerPostId): bool
    {
        return $origin === PublicationOrigin::TryPost
            && $publication->exists
            && $publication->remote_id !== $providerPostId
            && in_array($providerPostId, Arr::only($publication->provider_metadata ?? [], ['video_id', 'photo_id']), true);
    }

    /**
     * The `provider_metadata` after a discovery reported `$discovered`: what it
     * reports replaces what was stored, so a key it no longer reports goes, while
     * the keys only the metrics read writes stay. A discovery that reported
     * nothing (`null`, not an empty list) keeps it all.
     *
     * @param  array<string, mixed>|null  $discovered
     * @return array<string, mixed>|null
     */
    private static function providerMetadataAfterDiscovery(AnalyticsPublication $publication, ?array $discovered): ?array
    {
        if (is_null($discovered)) {
            return $publication->provider_metadata;
        }

        return [...$discovered, ...Arr::only($publication->provider_metadata ?? [], self::METRICS_ONLY_PROVIDER_METADATA)];
    }

    /**
     * A post listed again is read once more, so a stale discovery page cannot leave
     * it published: past its metrics window too (as a baseline read), and even when
     * today's read already ran.
     */
    private function readAgain(AnalyticsPublication $publication): void
    {
        $today = CarbonImmutable::now('UTC');

        CollectPublicationMetrics::dispatch(
            $publication->id,
            $today->toDateString(),
            baseline: $publication->provider_published_at->lessThan(SyncCadence::metricsWindowStart($publication->platform, $today)),
            refreshSameDay: true,
        )->afterCommit();
    }
}
