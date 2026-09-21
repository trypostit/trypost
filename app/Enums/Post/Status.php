<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum Status: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartiallyPublished = 'partially_published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('posts.status.draft'),
            self::Scheduled => __('posts.status.scheduled'),
            self::Publishing => __('posts.status.publishing'),
            self::Published => __('posts.status.published'),
            self::PartiallyPublished => __('posts.status.partially_published'),
            self::Failed => __('posts.status.failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'blue',
            self::Publishing => 'yellow',
            self::Published => 'green',
            self::PartiallyPublished => 'orange',
            self::Failed => 'red',
        };
    }

    /** Published, partially published, or failed — Finalize must not notify again. */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Published, self::PartiallyPublished, self::Failed => true,
            default => false,
        };
    }
}
