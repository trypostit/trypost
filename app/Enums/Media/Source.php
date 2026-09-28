<?php

declare(strict_types=1);

namespace App\Enums\Media;

/**
 * Origin of a media attachment on a post. `null`/absent means "uploaded by
 * the user" (legacy/unknown). The enum is forward-compatible so the regen
 * UI can decide on a per-source basis what actions are available (only `Ai`
 * exposes a "regenerate" button today, but `Unsplash`/`Giphy`/`Webdav` may surface
 * "fetch alternate" or attribution UIs later).
 */
enum Source: string
{
    case Ai = 'ai';
    case Unsplash = 'unsplash';
    case Giphy = 'giphy';
    case Webdav = 'webdav';
}
