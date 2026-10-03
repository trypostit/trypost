<?php

declare(strict_types=1);

namespace App\Models;

use App\Dto\MediaItem;
use Database\Factories\IdeaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Idea extends Model
{
    /** @use HasFactory<IdeaFactory> */
    use HasFactory, HasUuids;

    public const int MAX_MEDIA = 10;

    public const int MAX_BATCH = 500;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'idea_stage_id',
        'title',
        'body',
        'media',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'position' => 'integer',
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

    public function stage(): BelongsTo
    {
        return $this->belongsTo(IdeaStage::class, 'idea_stage_id');
    }

    public function ownedMedia(): HasMany
    {
        return $this->hasMany(Media::class, 'idea_id')->orderBy('order');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(WorkspaceLabel::class);
    }
}
