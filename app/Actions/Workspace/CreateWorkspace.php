<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\UserWorkspace\Role;
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
        $attributes = array_filter([
            'name' => data_get($data, 'name'),
            'brand_website' => data_get($data, 'brand_website'),
            'brand_description' => data_get($data, 'brand_description'),
            'brand_voice_traits' => data_get($data, 'brand_voice_traits'),
            'brand_color' => data_get($data, 'brand_color'),
            'background_color' => data_get($data, 'background_color'),
            'text_color' => data_get($data, 'text_color'),
            'content_language' => data_get($data, 'content_language', app()->getLocale()),
        ], static fn ($value): bool => $value !== null);

        $workspace = DB::transaction(function () use ($user, $attributes): Workspace {
            $workspace = Workspace::create([
                ...$attributes,
                'account_id' => $user->account_id,
                'user_id' => $user->id,
            ]);

            // Creator becomes Admin of the workspace they made. The Account Owner
            // is resolved separately (via account.owner_id) and outranks this role.
            $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
            $user->switchWorkspace($workspace);

            return $workspace;
        });

        $user->account?->syncWorkspaceQuantity();

        return $workspace;
    }
}
