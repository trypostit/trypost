<?php

declare(strict_types=1);

namespace App\Enums\GoogleBusiness;

/**
 * Leftover CTA Google deprecated. Use TopicType::Offer instead.
 *
 * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.localPosts#ActionType
 */
enum DeprecatedCtaAction: string
{
    case GetOffer = 'GET_OFFER';
}
