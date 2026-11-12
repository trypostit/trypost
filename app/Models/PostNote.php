<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class PostNote extends Model
{
    /** @use HasFactory<PostNoteFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'post_id',
        'user_id',
        'body',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(PostNoteReaction::class)->oldest()->orderBy('id');
    }

    /**
     * The note's reactions grouped by emoji, in the order each emoji was
     * first used, with who reacted. Load `reactions.user` first.
     *
     * @return list<array{emoji: string, count: int, users: list<array{id: string, name: string}>}>
     */
    public function reactionSummary(): array
    {
        return $this->reactions
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
