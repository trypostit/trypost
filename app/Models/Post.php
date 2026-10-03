<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Media\SyncOwnedMedia;
use App\Dto\MediaItem;
use App\Enums\Media\Type;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Origin;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Observers\PostObserver;
use App\Support\Media\MediaCopyBatch;
use Carbon\CarbonInterface;
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

    public const int CONTENT_MAX_LENGTH = SocialAccount::X_LONG_POST_LENGTH;

    protected $fillable = [
        'workspace_id',
        'post_group_id',
        'user_id',
        'content',
        'media',
        'status',
        'schedule_mode',
        'recurrence_interval',
        'recurrence_frequency',
        'recurrence_remaining',
        'recurrence_anchor_at',
        'recurrence_origin_at',
        'created_via',
        'origin',
        'repurpose_item_id',
        'scheduled_at',
        'published_at',
        'approval_requested_at',
        'approval_requested_by',
        'approved_by',
        'approved_at',
        'approval_queue_position',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'origin' => Origin::DEFAULT->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'schedule_mode' => ScheduleMode::class,
            'recurrence_interval' => 'integer',
            'recurrence_frequency' => RecurrenceFrequency::class,
            'recurrence_remaining' => 'integer',
            'recurrence_anchor_at' => 'datetime',
            'recurrence_origin_at' => 'datetime',
            'created_via' => CreatedVia::class,
            'origin' => Origin::class,
            'media' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'approval_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'approval_queue_position' => QueuePosition::class,
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvalRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_requested_by');
    }

    /**
     * Who asked for approval: the member who sent the post for approval, or
     * its author for requests stored before that was recorded.
     */
    public function approvalRequester(): ?User
    {
        return $this->approval_requested_by !== null ? $this->approvalRequestedBy : $this->user;
    }

    public function ownedMedia(): HasMany
    {
        return $this->hasMany(Media::class, 'post_id')->orderBy('order');
    }

    public function postPlatforms(): HasMany
    {
        return $this->hasMany(PostPlatform::class)->orderBy('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(PostNote::class);
    }

    /**
     * Posts created together with this one (one per destination), this post included.
     */
    public function groupPosts(): HasMany
    {
        return $this->hasMany(self::class, 'post_group_id', 'post_group_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(WorkspaceLabel::class);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Scheduled);
    }

    /**
     * Scheduled posts with an enabled destination on the given channel, upcoming after `$after`.
     */
    public function scopeScheduledOn(Builder $query, string $channelId, CarbonInterface $after): Builder
    {
        return $query->scheduled()
            ->where('scheduled_at', '>', $after)
            ->whereHas('postPlatforms', fn (Builder $platforms) => $platforms->enabled()->where('social_account_id', $channelId));
    }

    /**
     * Posts holding their slot instant: scheduled posts and queue requests pending approval.
     */
    public function scopeHoldingSlot(Builder $query): Builder
    {
        return $query->where(fn (Builder $holding) => $holding->scheduled()
            ->orWhere(fn (Builder $pending) => $pending->pendingApproval()->where('schedule_mode', ScheduleMode::Queue)));
    }

    public function scopeOccupyingSlotsOn(Builder $query, string $channelId, CarbonInterface $after): Builder
    {
        return $query->holdingSlot()
            ->where('scheduled_at', '>', $after)
            ->whereHas('postPlatforms', fn (Builder $platforms) => $platforms->enabled()->where('social_account_id', $channelId));
    }

    public function scopePendingQueueRequestsOn(Builder $query, string $channelId, CarbonInterface $after): Builder
    {
        return $query->pendingApproval()
            ->where('schedule_mode', ScheduleMode::Queue)
            ->where('scheduled_at', '>', $after)
            ->whereHas('postPlatforms', fn (Builder $platforms) => $platforms->enabled()->where('social_account_id', $channelId));
    }

    public function scopeQueuedOn(Builder $query, string $channelId, CarbonInterface $after): Builder
    {
        return $query->scheduledOn($channelId, $after)->where('schedule_mode', ScheduleMode::Queue);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->scheduled()->where('scheduled_at', '<=', now());
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Draft);
    }

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', PostStatus::PendingApproval);
    }

    /**
     * Posts whose approval was asked by the user (see approvalRequester()).
     */
    public function scopeApprovalRequestedBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $requested): Builder => $requested
            ->where('posts.approval_requested_by', $user->id)
            ->orWhere(fn (Builder $legacy): Builder => $legacy
                ->whereNull('posts.approval_requested_by')
                ->where('posts.user_id', $user->id)));
    }

    /**
     * Hides the pending posts a requester did not ask for; null (an approver) sees every one.
     */
    public function scopeVisiblePendingApprovalsFor(Builder $query, ?User $requester): Builder
    {
        return $query->when($requester !== null, fn (Builder $visible): Builder => $visible->where(fn (Builder $inner): Builder => $inner
            ->where('posts.status', '!=', PostStatus::PendingApproval)
            ->orWhere(fn (Builder $own): Builder => $own->approvalRequestedBy($requester))));
    }

    public function scopeCreatedInTryPost(Builder $query): Builder
    {
        return $query->where('posts.origin', Origin::TryPost);
    }

    public function scopeImported(Builder $query): Builder
    {
        return $query->where('posts.origin', Origin::Network);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished]);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Failed);
    }

    /**
     * Newest attempt first: when the post went out, else when it was due to go
     * out, else when it was written. Never updated_at, which any later write
     * (a release backfill, a label) moves without the post being sent again.
     */
    public function scopeLatestAttempt(Builder $query): Builder
    {
        return $query->orderByRaw('COALESCE(posts.published_at, posts.scheduled_at, posts.created_at) DESC');
    }

    /**
     * Posts carrying any of the labels, plus posts without labels when $untagged is set; no filter when both are empty.
     *
     * @param  list<string>  $labelIds
     */
    public function scopeMatchingLabelFilter(Builder $query, array $labelIds, bool $untagged = false): Builder
    {
        return $query->when($labelIds !== [] || $untagged, fn (Builder $filtered): Builder => $filtered->where(fn (Builder $inner): Builder => $inner
            ->when($labelIds !== [], fn (Builder $any): Builder => $any->whereHas('labels', fn (Builder $labels): Builder => $labels->whereIn('workspace_labels.id', $labelIds)))
            ->when($untagged, fn (Builder $none): Builder => $none->orWhereDoesntHave('labels'))));
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
     * Moves a post being published right now to the current time, remembering the
     * occurrence it consumed so a recurring series keeps its rhythm. Callers read
     * that occurrence before they overwrite scheduled_at.
     */
    public function moveScheduleToNow(?CarbonInterface $occurrence): void
    {
        $this->update([
            'scheduled_at' => now(),
            'recurrence_anchor_at' => $this->isRecurring()
                ? ($this->recurrence_anchor_at ?? $occurrence)
                : null,
        ]);
    }

    /**
     * The occurrence this post stands for in its series: the one publish-now
     * consumed, or its scheduled time.
     */
    public function currentOccurrence(): ?CarbonInterface
    {
        return $this->recurrence_anchor_at ?? $this->scheduled_at;
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_frequency !== null && $this->recurrence_interval !== null;
    }

    /**
     * @return array{recurrence_interval: null, recurrence_frequency: null, recurrence_remaining: null, recurrence_anchor_at: null, recurrence_origin_at: null}
     */
    public static function withoutRecurrence(): array
    {
        return [
            'recurrence_interval' => null,
            'recurrence_frequency' => null,
            'recurrence_remaining' => null,
            'recurrence_anchor_at' => null,
            'recurrence_origin_at' => null,
        ];
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
     * Append items (by `id` or `upload_token`) after the post's current media,
     * under a row lock so concurrent writers don't overwrite each other's
     * appends. Every item ends as a row this post owns.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function appendMedia(array $items, ?MediaCopyBatch $batch = null): void
    {
        $append = function (MediaCopyBatch $batch) use ($items): void {
            $fresh = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            SyncOwnedMedia::execute($fresh, [...($fresh->media ?? []), ...array_values($items)], $batch);

            $this->setRawAttributes($fresh->getAttributes(), true);
        };

        if ($batch !== null) {
            DB::transaction(fn () => $append($batch));

            return;
        }

        MediaCopyBatch::run($append);
    }
}
