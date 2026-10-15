<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Changelog\Tag;
use Database\Factories\ChangelogEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A "What's New" announcement. The rows are written only by `changelog:sync`
 * from the Markdown files under `changelog/`; never edit them by hand. The
 * cover is the public file `images/changelog/<slug>.png`.
 */
class ChangelogEntry extends Model
{
    /** @use HasFactory<ChangelogEntryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'title',
        'tags',
        'body',
        'published_on',
    ];

    protected function casts(): array
    {
        return [
            'tags' => AsEnumCollection::of(Tag::class),
            'published_on' => 'date',
        ];
    }

    public function views(): HasMany
    {
        return $this->hasMany(ChangelogEntryView::class);
    }

    /**
     * Entries whose date has come. The "What's New" list shows all of them.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereDate('published_on', '<=', today());
    }

    /**
     * Published entries dated on or after the day the user signed up: the only
     * ones the sidebar card may announce, so a new user never gets the backlog.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAnnouncedTo(Builder $query, User $user): void
    {
        $query->published()->whereDate('published_on', '>=', $user->created_at->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeWithSeenBy(Builder $query, User $user): void
    {
        $query->withExists(['views as seen' => fn (Builder $views) => $views->where('user_id', $user->id)]);
    }

    /**
     * Adds the user's `reaction` to each entry (null when they gave none).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithReactionBy(Builder $query, User $user): void
    {
        $query->addSelect(['reaction' => ChangelogEntryView::query()
            ->select('reaction')
            ->whereColumn('changelog_entry_id', 'changelog_entries.id')
            ->where('user_id', $user->id)
            ->limit(1)]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('published_on')->orderByDesc('slug');
    }

    public function coverUrl(): string
    {
        return asset("images/changelog/{$this->slug}.png");
    }

    public function bodyHtml(): string
    {
        return Str::markdown($this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function excerpt(): string
    {
        return Str::limit(Str::squish(strip_tags($this->bodyHtml())), 160);
    }
}
