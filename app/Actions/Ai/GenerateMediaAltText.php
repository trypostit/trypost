<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Ai\Agents\MediaAltTextGenerator;
use App\Models\Media;
use App\Models\User;
use App\Support\PostMediaRules;
use Laravel\Ai\Files\Image;

final class GenerateMediaAltText
{
    public static function execute(User $user, Media $asset): string
    {
        $response = (new MediaAltTextGenerator($user->locale))->prompt(
            'Write the alt text for this image.',
            attachments: [Image::fromStorage($asset->path)],
        );

        return mb_substr(trim((string) $response), 0, PostMediaRules::ALT_TEXT_MAX_LENGTH);
    }
}
