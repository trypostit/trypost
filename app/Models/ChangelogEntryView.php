<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChangelogEntryViewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user opened a changelog entry. One row per user and entry.
 */
class ChangelogEntryView extends Model
{
    /** @use HasFactory<ChangelogEntryViewFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'changelog_entry_id',
        'user_id',
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
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
