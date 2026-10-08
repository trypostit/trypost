<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum Status: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('posts.status.draft'),
            self::PendingApproval => __('posts.status.pending_approval'),
            self::Scheduled => __('posts.status.scheduled'),
            self::Publishing => __('posts.status.publishing'),
            self::Published => __('posts.status.published'),
            self::Failed => __('posts.status.failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingApproval => 'orange',
            self::Scheduled => 'blue',
            self::Publishing => 'yellow',
            self::Published => 'green',
            self::Failed => 'red',
        };
    }

    /** Published or failed — Finalize must not notify again. */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Published, self::Failed => true,
            default => false,
        };
    }
}
