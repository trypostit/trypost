<?php

declare(strict_types=1);

namespace App\Enums\Activity;

/**
 * The activity log is split by `log_name`: what backoffice admins did, and,
 * later, what happened inside a workspace (its audit, with `workspace_id`).
 */
enum LogName: string
{
    case Admin = 'admin';
    case Workspace = 'workspace';
}
