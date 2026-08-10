<?php

declare(strict_types=1);

namespace App\Models;

use App\DataTransferObjects\MediaItem;
use App\Enums\Media\Type;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Observers\PostObserver;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[ObservedBy([PostObserver::class])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'content',
        'media',
        'status',
        'created_via',
        'scheduled_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'created_via' => CreatedVia::class,
            'media' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Get media items as a collection of MediaItem DTOs.
     *
     * @return Collection<int, MediaItem>
     */
    protected function mediaItems(): Attribute
    {
        return Attribute::make(
            get: fn () => collect($this->media ?? [])->map(fn (array $item) => MediaItem::fromArray($item)),
        );
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function postPlatforms(): HasMany
    {
        return $this->hasMany(PostPlatform::class)->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(WorkspaceLabel::class);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Scheduled);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->scheduled()->where('scheduled_at', '<=', now());
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Draft);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished]);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Failed);
    }

    public function markAsPublishing(): void
    {
        $this->update(['status' => PostStatus::Publishing]);
    }

    public function markAsPublished(): void
    {
        $this->update([
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function markAsPartiallyPublished(): void
    {
        $this->update([
            'status' => PostStatus::PartiallyPublished,
            'published_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => PostStatus::Failed]);
    }

    /**
     * MediaTypes accepted by this post — the intersection of what every
     * enabled platform allows. With no platform enabled, accept anything.
     *
     * @return array<Type>
     */
    public function allowedMediaTypes(): array
    {
        $platforms = $this->postPlatforms()
            ->enabled()
            ->with('socialAccount')
            ->get()
            ->pluck('socialAccount.platform')
            ->filter();

        return self::allowedMediaTypesFor($platforms);
    }

    /**
     * Media types acceptable across a set of platforms (intersection; empty = all).
     *
     * @param  Collection<int, Platform>  $platforms
     * @return array<Type>
     */
    public static function allowedMediaTypesFor(Collection $platforms): array
    {
        if ($platforms->isEmpty()) {
            return Type::cases();
        }

        $sets = $platforms
            ->map(fn (Platform $platform) => array_map(fn ($type) => $type->value, $platform->allowedMediaTypes()))
            ->all();

        return array_map(
            Type::from(...),
            array_values(array_intersect(...$sets)),
        );
    }

    /**
     * Append items to the JSON `media` column under a row lock so
     * concurrent writers don't overwrite each other's appends.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function appendMedia(array $items): void
    {
        DB::transaction(function () use ($items): void {
            $fresh = static::whereKey($this->id)->lockForUpdate()->first();
            $fresh->update([
                'media' => collect($fresh->media ?? [])->concat($items)->all(),
            ]);
            $this->setRawAttributes($fresh->getAttributes(), true);
        });
    }
}
