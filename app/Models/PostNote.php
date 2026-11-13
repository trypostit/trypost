<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostNote extends Model
{
    /** @use HasFactory<PostNoteFactory> */
    use HasFactory, HasUuids;

    /** What a note is shown with: its author, its reactions and who it mentions. */
    public const DISPLAY_RELATIONS = ['user.avatarMedia', 'reactions.user', 'mentions'];

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

    /** The members the note mentions by id (`@[user:{id}]`), kept in step with its body. */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_note_mentions');
    }
}
