<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Media\Type as MediaType;
use App\Exceptions\MediaOwnershipViolation;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasUuids;

    public const string COLLECTION_MEDIA = 'media';

    public const string COLLECTION_UPLOADS = 'uploads';

    /**
     * The old workspace library's collection. Read only by `media:adopt-library`
     * and the audit, which must still find those rows on installs that deploy later.
     */
    public const string LIBRARY_COLLECTION = 'assets';

    protected $table = 'medias';

    protected $appends = ['url'];

    protected $fillable = [
        'workspace_id',
        'post_id',
        'idea_id',
        'rss_feed_item_id',
        'mediable_id',
        'mediable_type',
        'group_id',
        'collection',
        'type',
        'path',
        'original_filename',
        'mime_type',
        'size',
        'order',
        'meta',
        'upload_token',
    ];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'size' => 'integer',
            'order' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Media $media): void {
            if ($media->workspace_id === null && $media->mediable_type === Relation::getMorphAlias(Workspace::class)) {
                $media->workspace_id = $media->mediable_id;
            }

            if ($media->ownerCount() !== 1) {
                throw MediaOwnershipViolation::for($media);
            }

            if ($media->workspace_id === null && $media->mediable_type !== Relation::getMorphAlias(User::class)) {
                throw MediaOwnershipViolation::for($media);
            }

            if (! $media->isDirty(['workspace_id', 'post_id', 'idea_id', 'rss_feed_item_id', 'mediable_type', 'mediable_id'])) {
                return;
            }

            $ownerWorkspaceId = $media->ownerWorkspaceId();

            if ($ownerWorkspaceId !== null && $ownerWorkspaceId !== $media->workspace_id) {
                throw MediaOwnershipViolation::workspaceMismatch($media, $ownerWorkspaceId);
            }
        });
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    public function rssFeedItem(): BelongsTo
    {
        return $this->belongsTo(RssFeedItem::class);
    }

    public function scopeTemporaryUploads(Builder $query): void
    {
        $query->where('collection', self::COLLECTION_UPLOADS);
    }

    public function ownerCount(): int
    {
        return collect([
            $this->post_id,
            $this->idea_id,
            $this->rss_feed_item_id,
            $this->mediable_type !== null && $this->mediable_id !== null ? $this->mediable_id : null,
        ])->filter(fn (?string $owner): bool => $owner !== null)->count();
    }

    /**
     * The workspace of the row's owner; null for a user avatar or an owner
     * that does not exist (the foreign key rejects that one).
     */
    public function ownerWorkspaceId(): ?string
    {
        return match (true) {
            $this->post_id !== null => Post::query()->whereKey($this->post_id)->value('workspace_id'),
            $this->idea_id !== null => Idea::query()->whereKey($this->idea_id)->value('workspace_id'),
            $this->rss_feed_item_id !== null => RssFeed::query()
                ->whereIn('id', RssFeedItem::query()->whereKey($this->rss_feed_item_id)->select('rss_feed_id'))
                ->value('workspace_id'),
            $this->mediable_type === Relation::getMorphAlias(Workspace::class) => $this->mediable_id,
            default => null,
        };
    }

    public function issueUploadToken(): void
    {
        if ($this->upload_token !== null) {
            return;
        }

        $this->upload_token = (string) Str::uuid();
        $this->save();
    }

    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::url($this->path),
        );
    }

    public function isVideo(): bool
    {
        return MediaType::classify($this->mime_type, $this->path) === MediaType::Video;
    }

    public function isImage(): bool
    {
        return MediaType::classify($this->mime_type, $this->path) === MediaType::Image;
    }

    public function isDocument(): bool
    {
        return MediaType::classify($this->mime_type, $this->path) === MediaType::Document;
    }

    public function getTemporaryUrl(int $expirationMinutes = 60): string
    {
        return Storage::temporaryUrl(
            $this->path,
            now()->addMinutes($expirationMinutes)
        );
    }

    public function delete(): bool
    {
        // Only delete the file if no other media records use the same path
        $otherMediaWithSamePath = static::where('path', $this->path)
            ->where('id', '!=', $this->id)
            ->exists();

        if (! $otherMediaWithSamePath) {
            Storage::delete($this->path);
        }

        return parent::delete();
    }
}
