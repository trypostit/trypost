<?php

declare(strict_types=1);

namespace App\Enums\Activity;

/**
 * The activity log is split by `log_name`: what admins did.
 */
enum LogName: string
{
    case Admin = 'admin';
}
