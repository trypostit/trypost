<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use Database\Factories\PostPlatformFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PostPlatform extends Model
{
    /** @use HasFactory<PostPlatformFactory> */
    use HasFactory, HasUuids;

    protected $appends = [
        'display_name',
        'display_username',
        'display_avatar',
        'connection_issue_code',
    ];

    protected $fillable = [
        'post_id',
        'social_account_id',
        'google_business_profile_location_id',
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

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function googleBusinessProfileLocation(): BelongsTo
    {
        return $this->belongsTo(GoogleBusinessProfileLocation::class);
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

    /**
     * Get display name, falling back to snapshot if account was deleted.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->platform === SocialPlatform::GoogleBusinessProfile) {
            return $this->platform_name
                ?? $this->googleBusinessProfileLocation?->title
                ?? $this->platform->label();
        }

        return $this->socialAccount?->accountDisplayName() ?? $this->platform_name ?? $this->platform->label();
    }

    /**
     * Get username, falling back to snapshot if account was deleted.
     */
    public function getDisplayUsernameAttribute(): ?string
    {
        if ($this->platform === SocialPlatform::GoogleBusinessProfile) {
            return $this->platform_username ?? $this->googleBusinessProfileLocation?->store_code;
        }

        return $this->socialAccount?->username ?? $this->platform_username;
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

    public function getConnectionIssueCodeAttribute(): ?string
    {
        if ($this->platform === SocialPlatform::GoogleBusinessProfile
            && (! $this->googleBusinessProfileLocation || ! $this->googleBusinessProfileLocation->is_selected)) {
            return 'gbp_location_disconnected';
        }

        if (! $this->socialAccount) {
            return 'account_disconnected';
        }

        if (! $this->socialAccount->is_active) {
            return 'account_inactive';
        }

        if ($this->socialAccount->status === \App\Enums\SocialAccount\Status::TokenExpired) {
            return 'account_token_expired';
        }

        if ($this->socialAccount->status !== \App\Enums\SocialAccount\Status::Connected) {
            return 'account_disconnected';
        }

        return null;
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

    public function markAsSubmitted(string $platformPostId, ?string $platformUrl = null, Status $status = Status::Submitted): void
    {
        $this->update([
            'status' => $status,
            'platform_post_id' => $platformPostId,
            'platform_url' => $platformUrl,
            'submitted_at' => now(),
            'error_message' => null,
            'error_context' => null,
        ]);
    }

    public function markAsRejected(string $message, ?array $context = null): void
    {
        $this->update([
            'status' => Status::Rejected,
            'error_message' => $message,
            'error_context' => $context,
            'last_reconciled_at' => now(),
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
