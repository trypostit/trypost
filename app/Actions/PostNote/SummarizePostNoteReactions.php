<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Models\PostNote;
use App\Models\PostNoteReaction;
use Illuminate\Support\Collection;

final class SummarizePostNoteReactions
{
    /**
     * The note's reactions grouped by emoji, in the order each emoji was
     * first used, with who reacted. Load `reactions.user` first.
     *
     * @return list<array{emoji: string, count: int, users: list<array{id: string, name: string}>}>
     */
    public static function execute(PostNote $note): array
    {
        return $note->reactions
            ->groupBy('emoji')
            ->map(fn (Collection $reactions, string $emoji): array => [
                'emoji' => $emoji,
                'count' => $reactions->count(),
                'users' => $reactions->map(fn (PostNoteReaction $reaction): array => [
                    'id' => $reaction->user_id,
                    'name' => $reaction->user->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
