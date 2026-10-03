<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Media;
use LogicException;

/**
 * Raised when a `medias` row would be saved without exactly one owner (post,
 * idea, feed item or mediable), without a workspace while its owner is not a
 * user, or with a workspace other than its owner's.
 */
class MediaOwnershipViolation extends LogicException
{
    public static function for(Media $media): self
    {
        return new self(sprintf(
            'Media [%s] must have exactly one owner and a workspace unless owned by a user; found %d owner(s), workspace [%s].',
            $media->id ?? 'new',
            $media->ownerCount(),
            $media->workspace_id ?? 'none',
        ));
    }

    public static function workspaceMismatch(Media $media, string $ownerWorkspaceId): self
    {
        return new self(sprintf(
            'Media [%s] has workspace [%s] but its owner belongs to workspace [%s].',
            $media->id ?? 'new',
            $media->workspace_id ?? 'none',
            $ownerWorkspaceId,
        ));
    }
}
