<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Activity\RecordAdminAction;
use App\Enums\Activity\AdminActionType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnblockUser
{
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Unblock a user; unblocking one who is not blocked changes nothing.
     */
    public function execute(User $admin, User $user): void
    {
        if (! $user->isBlocked()) {
            return;
        }

        DB::transaction(function () use ($admin, $user): void {
            $user->forceFill([
                'blocked_at' => null,
                'blocked_reason' => null,
            ])->save();

            $this->recordAdminAction->execute($admin, AdminActionType::UnblockUser, $user);
        });
    }
}
