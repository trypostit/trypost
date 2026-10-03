<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderSocialAccounts
{
    /**
     * @param  list<string>  $socialAccountIds
     */
    public static function execute(Workspace $workspace, array $socialAccountIds): void
    {
        DB::transaction(function () use ($workspace, $socialAccountIds): void {
            $currentIds = SocialAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $requested = array_values($socialAccountIds);

            if (count($requested) !== count($currentIds) || array_diff($currentIds, $requested) !== [] || array_diff($requested, $currentIds) !== []) {
                throw ValidationException::withMessages([
                    'social_account_ids' => __('channels.reorder.stale'),
                ]);
            }

            foreach ($requested as $position => $id) {
                SocialAccount::withoutGlobalScopes()->whereKey($id)->update(['position' => $position]);
            }
        });
    }
}
