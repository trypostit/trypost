<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Admin\AdminActionType;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class BlockUser
{
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Block a user: they lose every session now, cannot sign in or use the API
     * and MCP, and the scheduled posts of the account they own stay scheduled
     * until they are unblocked. An admin can never be blocked.
     */
    public function execute(User $admin, User $user, string $reason): void
    {
        if ($user->is($admin) || $user->isBackofficeAdmin()) {
            throw new DomainException('A backoffice admin cannot be blocked.');
        }

        DB::transaction(function () use ($admin, $user, $reason): void {
            $user->forceFill([
                'blocked_at' => now(),
                'blocked_reason' => $reason,
            ])->save();

            DB::table(config()->string('session.table'))->where('user_id', $user->id)->delete();

            $this->recordAdminAction->execute($admin, AdminActionType::BlockUser, $user, ['reason' => $reason]);
        });
    }
}
