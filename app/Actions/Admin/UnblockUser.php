<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Admin\AdminActionType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnblockUser
{
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    public function execute(User $admin, User $user): void
    {
        DB::transaction(function () use ($admin, $user): void {
            $user->forceFill([
                'blocked_at' => null,
                'blocked_reason' => null,
            ])->save();

            $this->recordAdminAction->execute($admin, AdminActionType::UnblockUser, $user);
        });
    }
}
