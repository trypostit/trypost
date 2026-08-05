<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helpers for Pinterest pin meta (title, description, link) shared by create/update flows.
 */
class PinterestPinMeta
{
    /**
     * When description is blank and the post has caption content, copy content into
     * meta.description so API/MCP/web saves match the settings-box semantics.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function seedDescription(array $meta, ?string $content): array
    {
        if (blank(data_get($meta, 'description')) && filled($content)) {
            $meta['description'] = mb_substr($content, 0, 800);
        }

        return $meta;
    }
}
