<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\LinkedInPublishException;

/**
 * Publishes posts to a LinkedIn company page on behalf of an administering member.
 */
class LinkedInPagePublisher extends AbstractLinkedInPublisher
{
    protected function platform(): Platform
    {
        return Platform::LinkedInPage;
    }

    protected function authorUrn(): string
    {
        $organizationId = $this->account->meta['organization_id'] ?? null;

        if (! $organizationId) {
            throw new LinkedInPublishException(
                userMessage: 'LinkedIn Page organization ID not configured',
                category: ErrorCategory::Permission,
            );
        }

        return "urn:li:organization:{$organizationId}";
    }
}
