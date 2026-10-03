<?php

declare(strict_types=1);

namespace App\Actions\PostTemplate;

use App\Models\PostTemplate;

class DeletePostTemplate
{
    public static function execute(PostTemplate $template): void
    {
        $template->delete();
    }
}
