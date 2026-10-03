<?php

declare(strict_types=1);

namespace App\Actions\PostTemplate;

use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;

class CreatePostTemplate
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(Workspace $workspace, User $user, array $data): PostTemplate
    {
        return PostTemplate::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'visibility' => data_get($data, 'visibility'),
            'emoji' => data_get($data, 'emoji'),
            'title' => data_get($data, 'title'),
            'description' => data_get($data, 'description'),
            'body' => data_get($data, 'body'),
        ]);
    }
}
