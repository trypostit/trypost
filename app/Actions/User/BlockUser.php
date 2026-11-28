<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Activity\RecordAdminAction;
use App\Enums\Activity\AdminActionType;
use App\Models\User;
use App\Support\Auth\DatabaseSessions;
use Illuminate\Support\Facades\DB;

class BlockUser
{
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Block a user: they cannot sign in or use the API and MCP, and the posts
     * of the account they own wait until they are unblocked. Their sessions
     * stored in the database are dropped now; with another session driver,
     * `LogoutBlockedUser` signs them out on their next request. Blocking a
     * blocked user changes nothing; that an admin is never blocked is
     * checked by `BlockUserRequest`.
     */
    public function execute(User $admin, User $user, string $reason): void
    {
        if ($user->isBlocked()) {
            return;
        }

        DB::transaction(function () use ($admin, $user, $reason): void {
            $user->forceFill([
                'blocked_at' => now(),
                'blocked_reason' => $reason,
            ])->save();

            DatabaseSessions::forget($user);

            $this->recordAdminAction->execute($admin, AdminActionType::BlockUser, $user, ['reason' => $reason]);
        });
    }
}
