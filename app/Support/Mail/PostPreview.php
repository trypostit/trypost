<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Post;

class PostPreview
{
    /**
     * @return array{text: string, image: ?array{url: string, alt: string, width: int}, attachment: ?string}
     */
    public static function from(Post $post): array
    {
        $media = $post->media_items->first();
        $image = null;

        if ($media?->isImage() && filled($media->url)) {
            $width = $media->width();
            $height = $media->height();
            $scale = $width > 0 && $height > 0 ? min(1, 440 / $width, 280 / $height) : null;

            $image = [
                'url' => $media->url,
                'alt' => $media->altText() ?? '',
                'width' => $scale !== null ? max(1, (int) round($width * $scale)) : 240,
            ];
        }

        return [
            'text' => PostExcerpt::from($post->content, 500),
            'image' => $image,
            'attachment' => $media?->original_filename,
        ];
    }
}
