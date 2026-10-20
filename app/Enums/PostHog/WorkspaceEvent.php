<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum WorkspaceEvent: string
{
    case MemberInvited = 'workspace.member_invited';
    case LimitHit = 'workspace.limit_hit';
}
