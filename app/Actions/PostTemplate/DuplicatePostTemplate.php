<?php

declare(strict_types=1);

namespace App\Actions\PostTemplate;

use App\Enums\PostTemplate\Visibility;
use App\Models\PostTemplate;
use App\Models\User;
use Illuminate\Support\Str;

class DuplicatePostTemplate
{
    public static function execute(PostTemplate $template, User $user, Visibility $visibility): PostTemplate
    {
        return PostTemplate::create([
            'workspace_id' => $template->workspace_id,
            'user_id' => $user->id,
            'visibility' => $visibility,
            'emoji' => $template->emoji,
            'title' => Str::substr(__('create.templates.copy_suffix', ['title' => $template->title]), 0, 120),
            'description' => $template->description,
            'body' => $template->body,
        ]);
    }
}
