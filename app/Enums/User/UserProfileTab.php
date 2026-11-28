<?php

declare(strict_types=1);

namespace App\Enums\User;

/**
 * The tabs of a user's admin profile. Only the open tab's data is built
 * on each visit.
 */
enum UserProfileTab: string
{
    case Overview = 'overview';
    case Workspaces = 'workspaces';
    case Posts = 'posts';
    case Integrations = 'integrations';
    case Billing = 'billing';
    case Activity = 'activity';
}
