<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Analytics\PublicationAvailability;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Models\AnalyticsPublication;
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
        );
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
