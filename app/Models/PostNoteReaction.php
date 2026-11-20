<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostNoteReactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An emoji a member reacted to a note with. One row per note, user and emoji.
 */
class PostNoteReaction extends Model
{
    /** @use HasFactory<PostNoteReactionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'post_note_id',
        'user_id',
        'emoji',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(PostNote::class, 'post_note_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
