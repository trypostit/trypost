<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;

/**
 * @return array<string, array{Platform, PublicationContentType, ContentType}>
 */
function specializedPublicationTypes(): array
{
    return [
        'instagram reel' => [Platform::Instagram, PublicationContentType::Reel, ContentType::InstagramReel],
        'instagram story' => [Platform::Instagram, PublicationContentType::Story, ContentType::InstagramStory],
        'instagram via facebook reel' => [Platform::InstagramFacebook, PublicationContentType::Reel, ContentType::InstagramReel],
        'instagram via facebook story' => [Platform::InstagramFacebook, PublicationContentType::Story, ContentType::InstagramStory],
        'facebook reel' => [Platform::Facebook, PublicationContentType::Reel, ContentType::FacebookReel],
        'facebook story' => [Platform::Facebook, PublicationContentType::Story, ContentType::FacebookStory],
        'tiktok image' => [Platform::TikTok, PublicationContentType::Image, ContentType::TikTokPhoto],
        'tiktok carousel' => [Platform::TikTok, PublicationContentType::Carousel, ContentType::TikTokPhoto],
        'pinterest video' => [Platform::Pinterest, PublicationContentType::Video, ContentType::PinterestVideoPin],
        'pinterest carousel' => [Platform::Pinterest, PublicationContentType::Carousel, ContentType::PinterestCarousel],
    ];
}

test('a specialized publication type maps to its own content type', function (Platform $platform, PublicationContentType $type, ContentType $expected) {
    expect(ContentType::fromPublication($platform, $type))->toBe($expected);
})->with(specializedPublicationTypes());

test('every other platform and publication type pair maps to the platform default', function () {
    $specialized = collect(specializedPublicationTypes())
        ->map(fn (array $row): string => "{$row[0]->value}:{$row[1]->value}")
        ->all();

    foreach (Platform::cases() as $platform) {
        foreach (PublicationContentType::cases() as $type) {
            $mapped = ContentType::fromPublication($platform, $type);

            expect(in_array($platform, $mapped->compatiblePlatforms(), true))->toBeTrue("{$platform->value}:{$type->value}");

            if (! in_array("{$platform->value}:{$type->value}", $specialized, true)) {
                expect($mapped)->toBe(ContentType::defaultFor($platform), "{$platform->value}:{$type->value}");
            }
        }
    }
});
