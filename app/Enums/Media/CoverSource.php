<?php

declare(strict_types=1);

namespace App\Enums\Media;

/**
 * Where a video's cover comes from (`media.*.meta.cover_source`): a frame of
 * the video (`cover_offset_ms`, and its screenshot in `meta.cover` for a
 * network that takes an image), or an image the user uploaded (`meta.cover`).
 * A video shared by several networks keeps both, so a network that only takes
 * a frame still has one.
 */
enum CoverSource: string
{
    case Frame = 'frame';
    case Image = 'image';
}
