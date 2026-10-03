<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\PostPlatform\ContentType;
use App\Models\AnalyticsPublication;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Support\Str;
use LogicException;

class SyncTryPostPublication
{
    public function __construct(
        private readonly ResolveAnalyticsAccountKey $accountKeys,
        private readonly UpsertAnalyticsPublication $publications,
    ) {}

    public function handle(PostPlatform $postPlatform): AnalyticsPublication
    {
        $postPlatform->loadMissing(['post', 'socialAccount']);
        $account = $postPlatform->socialAccount;

        if (! $account) {
            throw new LogicException('A live social account is required to capture publication identity.');
        }

        return $this->fromIdentity(
            TryPostPublicationIdentity::fromAccount($account, $this->accountKeys->for($account)),
            $postPlatform,
            $account,
        );
    }

    public function fromIdentity(
        TryPostPublicationIdentity $identity,
        PostPlatform $postPlatform,
        ?SocialAccount $liveAccount = null,
    ): AnalyticsPublication {
        return $this->publications->tryPost(
            $identity,
            $postPlatform,
            $this->normalizedContentType($postPlatform),
            $this->excerpt($postPlatform),
            $liveAccount,
        );
    }

    private function normalizedContentType(PostPlatform $postPlatform): PublicationContentType
    {
        $specializedType = match ($postPlatform->content_type) {
            ContentType::InstagramReel, ContentType::FacebookReel => PublicationContentType::Reel,
            ContentType::InstagramStory, ContentType::FacebookStory => PublicationContentType::Story,
            ContentType::YouTubeShort => PublicationContentType::Short,
            ContentType::TikTokVideo, ContentType::PinterestVideoPin => PublicationContentType::Video,
            ContentType::TikTokPhoto, ContentType::PinterestCarousel => PublicationContentType::Carousel,
            default => null,
        };

        if ($specializedType) {
            return $specializedType;
        }

        $media = $postPlatform->post?->media_items;

        if (! $media || $media->isEmpty()) {
            return PublicationContentType::Text;
        }

        if ($media->count() > 1) {
            return PublicationContentType::Carousel;
        }

        return $media->first()->isVideo()
            ? PublicationContentType::Video
            : PublicationContentType::Image;
    }

    private function excerpt(PostPlatform $postPlatform): ?string
    {
        $content = trim(html_entity_decode(strip_tags((string) $postPlatform->post?->content)));

        return $content === '' ? null : Str::limit($content, 500);
    }
}
