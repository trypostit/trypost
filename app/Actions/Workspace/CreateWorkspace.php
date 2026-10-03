<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(User $user, array $data): Workspace
    {
        $workspace = DB::transaction(function () use ($user, $data): Workspace {
            $workspace = Workspace::create([
                'name' => data_get($data, 'name'),
                'account_id' => $user->account_id,
                'user_id' => $user->id,
            ]);

            $workspace->members()->attach($user->id, ['is_admin' => true, 'requires_approval' => false]);
            $user->switchWorkspace($workspace);

            return $workspace;
        });

        return $workspace;
    }
}
