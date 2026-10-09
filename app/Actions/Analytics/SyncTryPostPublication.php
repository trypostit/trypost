<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\PostPlatform\ContentType;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\ContentSanitizer;
use Illuminate\Support\Str;
use LogicException;

class SyncTryPostPublication
{
    public function __construct(
        private readonly ResolveAnalyticsAccountKey $accountKeys,
        private readonly UpsertAnalyticsPublication $publications,
    ) {}

    public function handle(Post $post): AnalyticsPublication
    {
        $post->loadMissing('socialAccount');
        $account = $post->socialAccount;

        if (! $account) {
            throw new LogicException('A live social account is required to capture publication identity.');
        }

        return $this->fromIdentity(
            TryPostPublicationIdentity::fromAccount($account, $this->accountKeys->for($account)),
            $post,
            $account,
        );
    }

    public function fromIdentity(
        TryPostPublicationIdentity $identity,
        Post $post,
        ?SocialAccount $liveAccount = null,
    ): AnalyticsPublication {
        return $this->publications->tryPost(
            $identity,
            $post,
            $this->normalizedContentType($post),
            $this->excerpt($post),
            $liveAccount,
        );
    }

    private function normalizedContentType(Post $post): PublicationContentType
    {
        $specializedType = match ($post->content_type) {
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

        $media = $post->media_items;

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

    private function excerpt(Post $post): ?string
    {
        $content = app(ContentSanitizer::class)->plainText((string) $post->content);

        return $content === '' ? null : Str::limit($content, 500);
    }
}
