<?php

declare(strict_types=1);

namespace App\Actions\Activity;

use App\Enums\Activity\AdminActionType;
use App\Enums\Activity\LogName;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAdminAction
{
    /**
     * Write what an admin did to a subject in the `admin` log. The
     * admin is always passed in, never read from the session: while
     * impersonating, the signed-in user is the impersonated one.
     *
     * @param  array<string, mixed>  $properties
     */
    public function execute(User $admin, AdminActionType $type, Model $subject, array $properties = []): void
    {
        activity(LogName::Admin->value)
            ->causedBy($admin)
            ->performedOn($subject)
            ->event($type->value)
            ->withProperties([
                ...$properties,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->log($type->value);
    }
}
