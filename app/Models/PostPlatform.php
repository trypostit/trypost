<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Observers\PostPlatformObserver;
use Database\Factories\PostPlatformFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[ObservedBy(PostPlatformObserver::class)]
class PostPlatform extends Model
{
    /** @use HasFactory<PostPlatformFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'post_id',
        'social_account_id',
        'enabled',
        'platform',
        'platform_name',
        'platform_username',
        'platform_avatar',
        'content_type',
        'status',
        'platform_post_id',
        'platform_url',
        'error_message',
        'error_context',
        'published_at',
        'submitted_at',
        'last_reconciled_at',
        'meta',
        'connection_warning_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'platform' => SocialPlatform::class,
            'content_type' => ContentType::class,
            'status' => Status::class,
            'published_at' => 'datetime',
            'submitted_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'meta' => 'array',
            'error_context' => 'array',
            'connection_warning_sent_at' => 'datetime',
        ];
    }

    /**
     * The text this platform row publishes: the per-platform `meta.content`
     * override when the user provided one (a short copy for X next to a long
     * Instagram caption, without splitting the post), otherwise the post's
     * shared content. Publishers and publish-time length validation must read
     * content through this method, never `post->content` directly.
     */
    public function resolvedContent(): ?string
    {
        $override = trim((string) data_get($this->meta, 'content'));

        return $override !== '' ? $override : $this->post?->content;
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Only platforms still enabled for publishing — disabled ones are
     * excluded from PublishPost, so anything else that mirrors publish
     * eligibility (previews, validation, proactive checks) must too.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('post_platforms.enabled', true);
    }

    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('post_platforms.enabled', false);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('post_platforms.status', Status::Published);
    }

    /**
     * Get display name, falling back to snapshot if account was deleted.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->socialAccount?->accountDisplayName() ?? $this->platform_name ?? $this->platform->label();
    }

    /**
     * Get username, falling back to snapshot if account was deleted.
     */
    public function getDisplayUsernameAttribute(): ?string
    {
        return $this->socialAccount?->username ?? $this->platform_username;
    }

    /**
     * "Facebook Page (@handle)" for emails and in-app notifications.
     * Username first, then display name (live account or the snapshot
     * kept on this row). When neither is set — or the account is gone
     * and there is no snapshot — just the platform name, never "(@)".
     */
    public function notificationLabel(): string
    {
        $identifier = $this->display_username
            ?: $this->socialAccount?->display_name
            ?: $this->platform_name;

        if (! filled($identifier) || $identifier === $this->platform->label()) {
            return $this->platform->label();
        }

        return "{$this->platform->label()} (@{$identifier})";
    }

    /**
     * Get avatar URL, falling back to snapshot if account was deleted.
     */
    public function getDisplayAvatarAttribute(): ?string
    {
        if ($this->socialAccount?->avatar_url) {
            return $this->socialAccount->avatar_url;
        }

        return $this->platform_avatar ? Storage::url($this->platform_avatar) : null;
    }

    public function markAsPublishing(): void
    {
        $this->update(['status' => Status::Publishing]);
    }

    public function markAsPublished(string $platformPostId, ?string $platformUrl = null): void
    {
        $now = now();

        $this->update([
            'status' => Status::Published,
            'platform_post_id' => $platformPostId,
            'platform_url' => $platformUrl,
            'published_at' => $now,
            'error_message' => null,
            'error_context' => null,
        ]);

        $this->socialAccount?->update(['last_used_at' => $now]);
    }

    /**
     * The provider accepted the post but has not finished reviewing it. It is
     * neither published nor failed until the review settles.
     */
    public function markAsPendingReview(string $platformPostId, ?string $platformUrl = null): void
    {
        $this->update([
            'status' => Status::PendingReview,
            'platform_post_id' => $platformPostId,
            'platform_url' => $platformUrl,
            'submitted_at' => $this->submitted_at ?? now(),
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
    public function markAsRejected(string $platformPostId, ?string $platformUrl, string $errorMessage, ?array $errorContext = null): void
    {
        $this->update([
            'status' => Status::Rejected,
            'platform_post_id' => $platformPostId,
            'platform_url' => $platformUrl,
            'error_message' => $errorMessage,
            'error_context' => $errorContext,
        ]);
    }

    public function markAsFailed(string $errorMessage, ?array $errorContext = null): void
    {
        $this->update([
            'status' => Status::Failed,
            'error_message' => $errorMessage,
            'error_context' => $errorContext,
            'platform_post_id' => null,
            'platform_url' => null,
        ]);
    }
}
