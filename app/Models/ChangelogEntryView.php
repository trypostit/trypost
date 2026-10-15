<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Changelog\Reaction;
use App\Observers\ChangelogEntryViewObserver;
use Database\Factories\ChangelogEntryViewFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user opened a changelog entry, and their answer to "Do you like it?".
 * One row per user and entry.
 */
#[ObservedBy([ChangelogEntryViewObserver::class])]
class ChangelogEntryView extends Model
{
    /** @use HasFactory<ChangelogEntryViewFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'changelog_entry_id',
        'user_id',
        'viewed_at',
        'reaction',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'reaction' => Reaction::class,
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ChangelogEntry::class, 'changelog_entry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
