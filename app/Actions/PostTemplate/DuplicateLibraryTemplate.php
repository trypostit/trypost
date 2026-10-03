<?php

declare(strict_types=1);

namespace App\Actions\PostTemplate;

use App\Dto\LibraryTemplate;
use App\Enums\PostTemplate\Visibility;
use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class DuplicateLibraryTemplate
{
    public static function execute(LibraryTemplate $template, Workspace $workspace, User $user, Visibility $visibility): PostTemplate
    {
        return PostTemplate::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'visibility' => $visibility,
            'emoji' => $template->emoji,
            'title' => Str::substr(__('create.templates.copy_suffix', ['title' => $template->title()]), 0, 120),
            'description' => $template->description(),
            'body' => $template->body(),
        ]);
    }
}
