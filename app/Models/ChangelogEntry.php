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
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;

/**
 * A "What's New" announcement. The rows are written only by `changelog:sync`
 * from the folders under `resources/changelog/`; never edit them by hand.
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
        $query->where('published_on', '<=', today()->toDateString());
    }

    /**
     * Published entries dated on or after the day the user signed up: the only
     * ones the sidebar card may announce, so a new user never gets the backlog.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAnnouncedTo(Builder $query, User $user): void
    {
        $query->published()->where('published_on', '>=', $user->created_at->toDateString());
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
        $query->withAggregate(['views as reaction' => fn (Builder $views) => $views->where('user_id', $user->id)], 'reaction');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('published_on')->orderByDesc('slug');
    }

    /**
     * Whether a user may open the entry: the changelog is on and its date has come.
     */
    public function isVisible(): bool
    {
        return config('trypost.changelog.enabled') && $this->published_on->lte(today());
    }

    /**
     * The cover's versioned URL from the Vite build, or null when the build has
     * no such file, as with entries made by a factory.
     */
    public function coverUrl(): ?string
    {
        return rescue(fn (): string => Vite::asset("resources/changelog/{$this->slug}/cover.png"), report: false);
    }

    public function bodyHtml(): string
    {
        return once(fn (): string => Str::markdown($this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }

    public function excerpt(): string
    {
        return Str::of($this->bodyHtml())
            ->stripTags()
            ->pipe(fn (string $text): string => html_entity_decode($text, ENT_QUOTES | ENT_HTML5))
            ->squish()
            ->limit(160)
            ->toString();
    }
}
