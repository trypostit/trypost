<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Media\SyncOwnedMedia;
use App\Dto\MediaItem;
use App\Enums\Media\Type;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Origin;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        'platform',
        'content_type',
        'platform_name',
        'platform_username',
        'platform_avatar',
        'meta',
        'platform_url',
        'error_message',
        'error_context',
        'thread_reply_ids',
        'submitted_at',
        'last_reconciled_at',
        'connection_warning_sent_at',
        'retry_at',
        'publication_updated_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'error_context',
        'legacy_target_id',
        'scheduled_before_media_checks',
        'last_reconciled_at',
        'connection_warning_sent_at',
        'publication_updated_at',
        'platform_avatar',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'origin' => Origin::DEFAULT->value,
        'publish_status' => PublishStatus::DEFAULT->value,
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
            'publish_status' => PublishStatus::class,
            'platform' => Platform::class,
            'content_type' => ContentType::class,
            'meta' => 'array',
            'error_context' => 'array',
            'thread_reply_ids' => 'array',
            'submitted_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'connection_warning_sent_at' => 'datetime',
            'retry_at' => 'datetime',
            'publication_updated_at' => 'datetime',
            'scheduled_before_media_checks' => 'boolean',
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

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function analyticsPublication(): HasOne
    {
        return $this->hasOne(AnalyticsPublication::class);
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
     * Scheduled posts on the given channel, upcoming after `$after`.
     */
    public function scopeScheduledOn(Builder $query, string $channelId, CarbonInterface $after): Builder
    {
        return $query->scheduled()
            ->where('scheduled_at', '>', $after)
            ->where('posts.social_account_id', $channelId);
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
            ->where('posts.social_account_id', $channelId);
    }

    public function scopePendingQueueRequestsOn(Builder $query, string $channelId, CarbonInterface $after): Builder
    {
        return $query->pendingApproval()
            ->where('schedule_mode', ScheduleMode::Queue)
            ->where('scheduled_at', '>', $after)
            ->where('posts.social_account_id', $channelId);
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

    public function scopeLatestScheduledFirst(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN posts.scheduled_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('posts.scheduled_at')
            ->orderByDesc('posts.created_at')
            ->orderByDesc('posts.id');
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
        return $query->where('status', PostStatus::Published);
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
     * Posts on any of the channels; no filter when $channelIds is null.
     *
     * @param  list<string>|null  $channelIds
     */
    public function scopeOnChannels(Builder $query, ?array $channelIds): Builder
    {
        return $query->when($channelIds !== null, fn (Builder $filtered): Builder => $filtered->whereIn('posts.social_account_id', $channelIds));
    }

    /**
     * Posts the network confirmed as published.
     */
    public function scopePublicationPublished(Builder $query): Builder
    {
        return $query->where('posts.publish_status', PublishStatus::Published);
    }

    /**
     * Posts a network refused for a limit whose next attempt is due.
     */
    public function scopeDueForLimitRetry(Builder $query): Builder
    {
        return $query->where('posts.publish_status', PublishStatus::Retrying)
            ->whereNotNull('posts.retry_at')
            ->where('posts.retry_at', '<=', now());
    }

    public function scopeIncludedInAnalytics(Builder $query): Builder
    {
        return $query->whereIn('posts.platform', Platform::analyticsValues());
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
            'published_at' => $this->published_at ?? now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => PostStatus::Failed]);
    }

    /**
     * The post has a destination: a channel, or the snapshot of one that was deleted.
     */
    public function hasDestination(): bool
    {
        return $this->platform !== null;
    }

    /**
     * The post's channel still exists.
     */
    public function hasChannel(): bool
    {
        return $this->social_account_id !== null;
    }

    /**
     * Display name, falling back to the snapshot when the account was deleted.
     */
    public function getDisplayNameAttribute(): ?string
    {
        return $this->socialAccount?->accountDisplayName() ?? $this->platform_name ?? $this->platform?->label();
    }

    /**
     * Username, falling back to the snapshot when the account was deleted.
     */
    public function getDisplayUsernameAttribute(): ?string
    {
        return $this->socialAccount?->username ?? $this->platform_username;
    }

    /**
     * Avatar URL, falling back to the snapshot when the account was deleted.
     */
    public function getDisplayAvatarAttribute(): ?string
    {
        if ($this->socialAccount?->avatar_url) {
            return $this->socialAccount->avatar_url;
        }

        return $this->platform_avatar ? Storage::url($this->platform_avatar) : null;
    }

    /**
     * "Facebook Page (@handle)" for emails. Username first, then display name
     * (live account or the snapshot). When neither is set, just the platform
     * name, never "(@)". Empty for a draft without a channel.
     */
    public function notificationLabel(): string
    {
        if ($this->platform === null) {
            return '';
        }

        $identifier = $this->display_username
            ?: $this->socialAccount?->display_name
            ?: $this->platform_name;

        if (! filled($identifier) || $identifier === $this->platform->label()) {
            return $this->platform->label();
        }

        return "{$this->platform->label()} (@{$identifier})";
    }

    /**
     * Whether the publisher attaches the link preview card for the first URL.
     * False only when the user dropped the card in the composer.
     */
    public function attachesLinkPreview(): bool
    {
        return data_get($this->meta, 'link_preview') !== false;
    }

    public function markPublicationPublishing(): void
    {
        $this->writePublication(['publish_status' => PublishStatus::Publishing, 'retry_at' => null]);
    }

    /**
     * The network refused the publish for a limit: the post waits, not
     * failed, until the scheduler picks it up at `retry_at`.
     *
     * @param  array<string, mixed>  $errorContext
     */
    public function markPublicationWaitingForLimitRetry(CarbonInterface $retryAt, string $errorMessage, array $errorContext): void
    {
        $this->writePublication([
            'publish_status' => PublishStatus::Retrying,
            'retry_at' => $retryAt,
            'error_message' => $errorMessage,
            'error_context' => $errorContext,
        ]);
    }

    public function isPublicationWaitingForLimitRetry(): bool
    {
        return $this->publish_status === PublishStatus::Retrying && $this->retry_at?->isFuture() === true;
    }

    public function markPublicationPublished(string $platformPostId, ?string $platformUrl = null): void
    {
        $now = now();

        $this->writePublication([
            'publish_status' => PublishStatus::Published,
            'platform_post_id' => $platformPostId,
            'platform_url' => $platformUrl,
            'published_at' => $now,
            'retry_at' => null,
            'error_message' => null,
            'error_context' => null,
        ]);

        $this->socialAccount?->update(['last_used_at' => $now]);
    }

    /**
     * The provider accepted the post but has not finished reviewing it. It is
     * neither published nor failed until the review settles.
     */
    public function markPublicationPendingReview(string $platformPostId, ?string $platformUrl = null): void
    {
        $this->writePublication([
            'publish_status' => PublishStatus::PendingReview,
            'platform_post_id' => $platformPostId,
            'platform_url' => $platformUrl,
            'submitted_at' => $this->submitted_at ?? now(),
            'retry_at' => null,
            'error_message' => null,
            'error_context' => null,
        ]);
    }

    /**
     * The provider accepted the post and then refused it in review. Unlike a
     * failure, the remote row exists, so its id and URL are kept for support.
     *
     * @param  array<string, mixed>|null  $errorContext
     */
    public function markPublicationRejected(string $platformPostId, ?string $platformUrl, string $errorMessage, ?array $errorContext = null): void
    {
        $this->writePublication([
            'publish_status' => PublishStatus::Rejected,
            'platform_post_id' => $platformPostId,
            'platform_url' => $platformUrl,
            'retry_at' => null,
            'error_message' => $errorMessage,
            'error_context' => $errorContext,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $errorContext
     */
    public function markPublicationFailed(string $errorMessage, ?array $errorContext = null): void
    {
        $this->writePublication([
            'publish_status' => PublishStatus::Failed,
            'retry_at' => null,
            'error_message' => $errorMessage,
            'error_context' => $errorContext,
            'platform_post_id' => null,
            'platform_url' => null,
        ]);
    }

    /**
     * Writes the publication fields with their own clock, so a publish attempt
     * never moves `updated_at` and a label or note never moves the clock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function writePublication(array $attributes): void
    {
        static::withoutTimestamps(fn () => $this->forceFill([...$attributes, 'publication_updated_at' => now()])->save());
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
     * MediaTypes accepted by this post's channel. Without a channel, accept anything.
     *
     * @return array<Type>
     */
    public function allowedMediaTypes(): array
    {
        return self::allowedMediaTypesFor(collect([$this->socialAccount?->platform])->filter());
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
