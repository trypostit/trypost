<?php

declare(strict_types=1);

namespace App\Enums\GoogleBusiness;

/**
 * Google Business Profile `LocalPost.topicType` values we persist and author.
 * Official Local Posts v4 also has ALERT; we do not offer it.
 *
 * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.localPosts#LocalPostTopicType
 */
enum TopicType: string
{
    case Standard = 'STANDARD';
    case Event = 'EVENT';
    case Offer = 'OFFER';

    public static function fromMeta(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::Standard;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Google requires a LocalPost `event` object for EVENT and OFFER. */
    public function requiresEvent(): bool
    {
        return match ($this) {
            self::Event, self::Offer => true,
            self::Standard => false,
        };
    }

    /** Official schema: callToAction is ignored for topic type OFFER. */
    public function allowsCallToAction(): bool
    {
        return $this !== self::Offer;
    }
}
