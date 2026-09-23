<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Analytics\PublicationAvailability;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class UpsertAnalyticsPublication
{
    public function __construct(private readonly ResolveAnalyticsAccountKey $accountKeys) {}

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
        );
    }

    public function tryPost(
        TryPostPublicationIdentity $identity,
        PostPlatform $postPlatform,
        PublicationContentType $contentType,
        ?string $excerpt,
        ?SocialAccount $liveAccount = null,
    ): AnalyticsPublication {
        return $this->persist(
            identity: $identity,
            providerPostId: (string) $postPlatform->platform_post_id,
            providerPublishedAt: ($postPlatform->published_at ?? $postPlatform->updated_at)->toImmutable(),
            origin: PublicationOrigin::TryPost,
            contentType: $contentType,
            providerContentType: $postPlatform->content_type->value,
            permalink: $postPlatform->platform_url,
            excerpt: $excerpt,
            previewMetadata: null,
            providerMetadata: null,
            postPlatformId: $postPlatform->id,
            liveAccount: $liveAccount,
        );
    }

    public function reconcileTikTokPublicId(AnalyticsPublication $publication, string $publicId): void
    {
        DB::transaction(function () use ($publication, $publicId): void {
            $current = AnalyticsPublication::query()->lockForUpdate()->findOrFail($publication->id);

            if ($current->provider_post_id === $publicId || ! $current->post_platform_id) {
                return;
            }

            $discovered = AnalyticsPublication::query()
                ->where('workspace_id', $current->workspace_id)
                ->where('social_account_key', $current->social_account_key)
                ->where('network', $current->network)
                ->where('provider_post_id', $publicId)
                ->lockForUpdate()
                ->first();

            if ($discovered) {
                if ($discovered->post_platform_id && $discovered->post_platform_id !== $current->post_platform_id) {
                    throw new \LogicException('TikTok public id is already attached to another TryPost publication.');
                }

                $discovered->dailySnapshots()->lockForUpdate()->reorder()->lazyById(100)->each(function (AnalyticsPublicationDailySnapshot $snapshot) use ($current): void {
                    $existing = $current->dailySnapshots()
                        ->whereDate('snapshot_date', $snapshot->snapshot_date->toDateString())
                        ->lockForUpdate()
                        ->first();

                    if (! $existing) {
                        $snapshot->update(['analytics_publication_id' => $current->id]);

                        return;
                    }

                    if ($snapshot->collected_at->greaterThan($existing->collected_at)) {
                        $existing->fill($snapshot->only([
                            'collected_at', 'provider_observed_at', 'metrics',
                            'reactions_count', 'comments_count', 'shares_count', 'saves_count',
                            'views_count', 'impressions_count', 'reach_count',
                            'engagement_count', 'exposure_count', 'exposure_kind',
                            'watch_time_milliseconds', 'average_watch_time_milliseconds',
                        ]))->save();
                    }

                    $snapshot->delete();
                });

                $current->fill([
                    'permalink' => $discovered->permalink ?? $current->permalink,
                    'preview_metadata' => $discovered->preview_metadata ?? $current->preview_metadata,
                    'provider_metadata' => $discovered->provider_metadata ?? $current->provider_metadata,
                    'provider_synced_at' => $discovered->provider_synced_at ?? $current->provider_synced_at,
                ]);
                $discovered->delete();
            }

            $current->provider_post_id = $publicId;
            $current->save();

            PostPlatform::query()->whereKey($current->post_platform_id)->update([
                'platform_post_id' => $publicId,
                'platform_url' => $current->permalink,
            ]);

            $publication->setRawAttributes($current->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, mixed>|null  $previewMetadata
     * @param  array<string, mixed>|null  $providerMetadata
     */
    private function persist(
        TryPostPublicationIdentity $identity,
        string $providerPostId,
        \DateTimeInterface $providerPublishedAt,
        PublicationOrigin $origin,
        PublicationContentType $contentType,
        ?string $providerContentType,
        ?string $permalink,
        ?string $excerpt,
        ?array $previewMetadata,
        ?array $providerMetadata,
        ?string $postPlatformId = null,
        ?\DateTimeInterface $providerSyncedAt = null,
        ?SocialAccount $liveAccount = null,
    ): AnalyticsPublication {
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
                $postPlatformId,
                $providerSyncedAt,
                $liveAccount,
                true,
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
                $postPlatformId,
                $providerSyncedAt,
                $liveAccount,
                false,
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
        \DateTimeInterface $providerPublishedAt,
        PublicationOrigin $origin,
        PublicationContentType $contentType,
        ?string $providerContentType,
        ?string $permalink,
        ?string $excerpt,
        ?array $previewMetadata,
        ?array $providerMetadata,
        ?string $postPlatformId,
        ?\DateTimeInterface $providerSyncedAt,
        ?SocialAccount $liveAccount,
        bool $mayCreate,
    ): AnalyticsPublication {
        return DB::transaction(function () use ($contentType, $excerpt, $identity, $liveAccount, $mayCreate, $origin, $permalink, $postPlatformId, $previewMetadata, $providerContentType, $providerMetadata, $providerPostId, $providerPublishedAt, $providerSyncedAt): AnalyticsPublication {
            $identityFields = [
                'workspace_id' => $identity->workspaceId,
                'social_account_key' => $identity->socialAccountKey,
                'network' => $identity->network,
                'provider_post_id' => $providerPostId,
            ];
            $publication = AnalyticsPublication::query()
                ->where($identityFields)
                ->lockForUpdate()
                ->first();

            if (! $publication && $postPlatformId) {
                $publication = AnalyticsPublication::query()
                    ->where('post_platform_id', $postPlatformId)
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

            $values = [
                'social_account_id' => $liveAccount?->id
                    ?? SocialAccount::query()->whereKey($identity->socialAccountId)->value('id'),
                'post_platform_id' => $postPlatformId ?? $publication->post_platform_id,
                'platform_user_id' => $identity->platformUserId,
                'platform' => $identity->platform,
                'provider_published_at' => $providerPublishedAt,
                'origin' => $origin === PublicationOrigin::TryPost
                    ? PublicationOrigin::TryPost
                    : ($publication->origin ?? PublicationOrigin::External),
                'content_type' => $contentType,
                'availability' => PublicationAvailability::Available,
                'last_seen_at' => $now,
                'provider_synced_at' => $providerSyncedAt ?? $publication->provider_synced_at,
            ];

            foreach ([
                'provider_content_type' => $providerContentType,
                'permalink' => $permalink,
                'excerpt' => $excerpt,
                'preview_metadata' => $previewMetadata,
                'provider_metadata' => $providerMetadata,
                'account_display_name' => $identity->accountDisplayName,
                'account_username' => $identity->accountUsername,
                'account_avatar_url' => $identity->accountAvatarUrl,
            ] as $key => $value) {
                if ($value !== null) {
                    $values[$key] = $value;
                }
            }

            $publication->fill($values)->save();

            return $publication->refresh();
        });
    }
}
