<?php

declare(strict_types=1);

namespace App\Actions\PostTemplate;

use App\Models\PostTemplate;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdatePostTemplate
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(PostTemplate $template, User $user, array $data): PostTemplate
    {
        $visibility = data_get($data, 'visibility');

        if ($visibility !== null && $visibility !== $template->visibility->value && $template->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'visibility' => __('create.templates.errors.visibility_owner_only'),
            ]);
        }

        $template->update(array_intersect_key($data, array_flip(['visibility', 'emoji', 'title', 'description', 'body'])));

        return $template->refresh();
    }
}
