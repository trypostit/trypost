<?php

declare(strict_types=1);

namespace App\Enums\Activity;

enum AdminActionType: string
{
    case BlockUser = 'user.blocked';
    case UnblockUser = 'user.unblocked';
    case StartImpersonation = 'impersonation.started';
    case StopImpersonation = 'impersonation.stopped';
    case RetryFailedPostFromAdmin = 'post.retried';
    case CreateOffer = 'offer.created';
    case UpdateOffer = 'offer.updated';
    case EndOffer = 'offer.ended';
    case DeleteOffer = 'offer.deleted';
}
