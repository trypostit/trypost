<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

/**
 * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews#ReviewReply
 */
enum ReviewReplyState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public static function fromGoogle(?string $state): ?self
    {
        return match ($state) {
            'PENDING' => self::Pending,
            'APPROVED' => self::Approved,
            'REJECTED' => self::Rejected,
            default => null,
        };
    }
}
