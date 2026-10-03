<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Analytics\DispatchAccountAnalytics;
use App\Actions\SocialAccount\ApplyChannelDefaults;
use App\Casts\PostingScheduleCast;
use App\Enums\Notification\Type;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Jobs\SendNotification;
use App\Mail\AccountDisconnected;
use App\Models\Scopes\SocialAccountOrderScope;
use App\Observers\SocialAccountObserver;
use App\Support\GoogleBusinessResourceName;
use Database\Factories\SocialAccountFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[ObservedBy(SocialAccountObserver::class)]
#[ScopedBy(SocialAccountOrderScope::class)]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory, HasUuids;

    public const int X_LONG_POST_LENGTH = 25000;

    /**
     * @var list<string>
     */
    public const array X_LONG_POST_SUBSCRIPTIONS = ['Basic', 'Premium', 'PremiumPlus'];

    protected $fillable = [
        'workspace_id',
        'platform',
        'platform_user_id',
        'username',
        'display_name',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'meta',
        'status',
        'error_message',
        'disconnected_at',
        'last_used_at',
        'last_verified_at',
        'timezone',
        'posting_goal',
        'posting_schedule',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'meta',
    ];

    protected $appends = [
        'display_label',
        'handle_label',
    ];

    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'status' => Status::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'scopes' => 'array',
            'meta' => 'array',
            'position' => 'integer',
            'posting_goal' => 'integer',
            'posting_schedule' => PostingScheduleCast::class,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Whether this X account may publish posts longer than 280 characters:
     * any paid subscription, or a verified organization.
     */
    public function hasXLongPosts(): bool
    {
        return $this->platform === SocialPlatform::X
            && (in_array(data_get($this->meta, 'x_subscription_type'), self::X_LONG_POST_SUBSCRIPTIONS, true)
                || data_get($this->meta, 'x_verified_type') === 'business');
    }

    /**
     * The hard character cap for this account's posts. Same as the platform's,
     * except an X account with long posts.
     */
    public function maxContentLength(): int
    {
        return $this->hasXLongPosts() ? self::X_LONG_POST_LENGTH : $this->platform->maxContentLength();
    }

    public function contentOverflow(string $content, int $reserved = 0): int
    {
        return max(0, mb_strlen($content) + $reserved - $this->maxContentLength());
    }

    /**
     * Persist a freshly authorized identity.
     *
     * A reconnect only reuses its row when the provider returned the very same
     * identity. Authorizing a different account is refused instead of repointing
     * the card (and every post scheduled against it) at a stranger.
     *
     * @param  array<string, mixed>  $values
     */
    public static function connectIdentity(
        Workspace $workspace,
        SocialPlatform $platform,
        string $platformUserId,
        array $values,
        ?self $reconnect = null,
    ): self {
        // Two popups finishing at once for the same network must not interleave
        // a reconnect's update-and-realign transaction with a fresh insert.
        try {
            $account = Cache::lock("social_connect:{$workspace->id}:{$platform->network()}", 10)
                ->block(5, fn (): self => static::persistIdentity(
                    $workspace,
                    $platform,
                    $platformUserId,
                    $values,
                    $reconnect,
                ));

            if ((! $account->wasRecentlyCreated || $reconnect?->id === $account->id)
                && ! $account->wasChanged('status')) {
                app(DispatchAccountAnalytics::class)->handle($account);
            }

            return $account;
        } catch (LockTimeoutException) {
            throw NetworkAlreadyConnectedException::connectInProgress($platform);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function persistIdentity(
        Workspace $workspace,
        SocialPlatform $platform,
        string $platformUserId,
        array $values,
        ?self $reconnect,
    ): self {
        $values['platform'] = $platform;
        $values['platform_user_id'] = $platformUserId;

        $identity = [
            'platform' => $platform->value,
            'platform_user_id' => $platformUserId,
        ];

        if (
            $reconnect?->workspace_id === $workspace->id
            && $reconnect->platform->network() === $platform->network()
        ) {
            if ((string) $reconnect->platform_user_id !== $platformUserId) {
                throw NetworkAlreadyConnectedException::identityMismatch($platform);
            }

            $previousPlatform = $reconnect->platform;

            try {
                // The card and the targets that still have to publish through it
                // move together or not at all.
                DB::transaction(function () use ($reconnect, $values, $previousPlatform, $platform): void {
                    $reconnect->update($values);

                    static::realignUnpublishedTargets($reconnect, $previousPlatform, $platform);
                });
            } catch (UniqueConstraintViolationException) {
                throw new NetworkAlreadyConnectedException($platform);
            }

            return $reconnect;
        }

        try {
            $account = $workspace->socialAccounts()->updateOrCreate($identity, $values);
        } catch (UniqueConstraintViolationException) {
            $account = $workspace->socialAccounts()->where($identity)->firstOrFail();
            $account->update($values);

            return $account;
        }

        if ($account->wasRecentlyCreated) {
            static::applyChannelDefaults($account, $workspace);
        }

        return $account;
    }

    /**
     * A new channel starts in its connector's time zone (the workspace owner's
     * when connected by webhook, UTC as a last resort) with a three-a-week goal
     * and the network's recommended slots, so it has a usable schedule even if
     * the post-connect goal dialog is dismissed.
     */
    private static function applyChannelDefaults(self $account, Workspace $workspace): void
    {
        ApplyChannelDefaults::execute($account, auth()->user()?->timezone ?? $workspace->owner?->timezone);
    }

    /**
     * Reconnecting through the other variant of a network (Instagram directly
     * after Facebook, a LinkedIn profile after its page) moves the card to the
     * new platform. Post targets carry their own `platform` snapshot and that
     * snapshot is what picks the publisher, the queue and the scopes checked
     * before publishing, so a stale one fails the post on permissions it never
     * needed.
     *
     * Only targets that still have a publish ahead of them move. Published rows
     * record what really went out under a platform_post_id from that flavor of
     * the API; failed ones are terminal; a publishing one has a job mid-flight
     * that already read the snapshot it is working from.
     */
    private static function realignUnpublishedTargets(self $account, SocialPlatform $from, SocialPlatform $to): void
    {
        if ($from === $to) {
            return;
        }

        $awaitingPublish = [PostPlatformStatus::Pending, PostPlatformStatus::Retrying];

        $supported = array_values(array_map(
            fn (ContentType $contentType): string => $contentType->value,
            ContentType::forPlatform($to),
        ));

        $account->postPlatforms()
            ->whereIn('status', $awaitingPublish)
            ->whereNotIn('content_type', $supported)
            ->update(['content_type' => ContentType::defaultFor($to)->value]);

        $account->postPlatforms()
            ->whereIn('status', $awaitingPublish)
            ->update(['platform' => $to->value]);
    }

    public function postPlatforms(): HasMany
    {
        return $this->hasMany(PostPlatform::class);
    }

    public function analyticsSyncStates(): HasMany
    {
        return $this->hasMany(AnalyticsSyncState::class);
    }

    protected function isTokenExpired(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->token_expires_at && $this->token_expires_at->isPast(),
        );
    }

    protected function isTokenExpiringSoon(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->token_expires_at && $this->token_expires_at->isBefore(now()->addMinutes(15)),
        );
    }

    /**
     * Whether the token should be refreshed before use. Rotating-refresh-token
     * platforms are only refreshed once actually expired, to avoid rotating a
     * still-valid single-use refresh_token; extension-model platforms
     * (Instagram/Threads) must be refreshed while still valid because their
     * token can't be extended once expired.
     */
    public function needsProactiveTokenRefresh(): bool
    {
        return $this->is_token_expired
            || ($this->platform->extendsAccessTokenOnRefresh() && $this->is_token_expiring_soon);
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Storage::url($value) : null,
        );
    }

    protected function profileUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $username = $this->username;
                $platformUserId = $this->platform_user_id;

                return match ($this->platform) {
                    SocialPlatform::Facebook => ($username || $platformUserId)
                        ? 'https://facebook.com/'.($username ?: $platformUserId)
                        : null,
                    SocialPlatform::LinkedIn => $username ? "https://linkedin.com/in/{$username}" : null,
                    SocialPlatform::LinkedInPage => $username ? "https://linkedin.com/company/{$username}" : null,
                    SocialPlatform::X => $username ? "https://x.com/{$username}" : null,
                    SocialPlatform::TikTok => $username ? "https://tiktok.com/@{$username}" : null,
                    SocialPlatform::Instagram, SocialPlatform::InstagramFacebook => $username
                        ? "https://instagram.com/{$username}"
                        : null,
                    SocialPlatform::YouTube => $username ? "https://youtube.com/@{$username}" : null,
                    SocialPlatform::Threads => $username ? "https://threads.net/@{$username}" : null,
                    SocialPlatform::Bluesky => $username ? "https://bsky.app/profile/{$username}" : null,
                    SocialPlatform::Pinterest => $username ? "https://pinterest.com/{$username}" : null,
                    SocialPlatform::Mastodon => ($username && data_get($this->meta, 'instance'))
                        ? rtrim((string) data_get($this->meta, 'instance'), '/')."/@{$username}"
                        : null,
                    SocialPlatform::Telegram => $username ? "https://t.me/{$username}" : null,
                    SocialPlatform::GoogleBusiness => filled(data_get($this->meta, 'maps_uri'))
                        ? (string) data_get($this->meta, 'maps_uri')
                        : (filled(data_get($this->meta, 'location_id'))
                            ? GoogleBusinessResourceName::dashboardUrl((string) data_get($this->meta, 'location_id'))
                            : null),
                    default => null,
                };
            },
        );
    }

    /**
     * Friendly label for email templates — the display name wins over the
     * username when both are set.
     */
    public function accountDisplayName(): string
    {
        return $this->display_name ?: $this->username ?: $this->platform->label();
    }

    /**
     * The channel identity a new PostPlatform keeps, so the post still shows
     * who it went to after the account is renamed or disconnected.
     *
     * @return array{platform_name: string, platform_username: ?string, platform_avatar: ?string}
     */
    public function channelSnapshot(): array
    {
        return [
            'platform_name' => $this->accountDisplayName(),
            'platform_username' => $this->username,
            'platform_avatar' => $this->getRawOriginal('avatar_url'),
        ];
    }

    /**
     * Base URL of the Mastodon instance this account lives on, without a
     * trailing slash.
     */
    public function mastodonInstance(): string
    {
        return rtrim((string) data_get($this->meta, 'instance', config('trypost.platforms.mastodon.default_instance')), '/');
    }

    /**
     * Whether the token may read the account's statuses, private ones
     * included; without it only public statuses are readable, unauthenticated.
     */
    public function canReadMastodonStatuses(): bool
    {
        return count(array_intersect(['read', 'read:statuses'], $this->scopes ?? [])) > 0;
    }

    /**
     * Frontend-facing mirror of accountDisplayName() — appended to JSON so
     * Vue components stop re-implementing this fallback.
     */
    protected function displayLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->accountDisplayName(),
        );
    }

    /**
     * Account handle without the "@" prefix — the more specific identifier
     * (username) wins over the friendlier display name when both are set.
     * Templates that render their own "@" (e.g. platform previews) use this.
     * Connectors normally populate at least one of username/display_name
     * (TikTok Login Kit still returns display_name via user.info.basic;
     * username needs user.info.profile, which self-hosters may trim).
     * The platform label is a last-resort fallback, not an expected path.
     */
    protected function handleLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->username ?: $this->display_name ?: $this->platform->label(),
        );
    }

    public function markAsDisconnected(string $errorMessage): void
    {
        $lock = Cache::lock("social_account_status:{$this->id}", 10);

        if ($lock->get()) {
            try {
                $this->refresh();
                $wasConnected = $this->status !== Status::Disconnected;

                $this->update([
                    'status' => Status::Disconnected,
                    'error_message' => $errorMessage,
                    'disconnected_at' => now(),
                ]);

                if ($wasConnected && $this->workspace->owner) {
                    SendNotification::dispatch(
                        user: $this->workspace->owner,
                        type: Type::AccountDisconnected,
                        mailable: new AccountDisconnected($this),
                    );
                }
            } finally {
                $lock->release();
            }
        }
    }

    public function markAsTokenExpired(string $errorMessage, bool $notify = true): void
    {
        $lock = Cache::lock("social_account_status:{$this->id}", 10);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->refresh();
            $wasUsable = $this->status === Status::Connected;

            $this->update([
                'status' => Status::TokenExpired,
                'error_message' => $errorMessage,
                'disconnected_at' => $this->disconnected_at ?? now(),
            ]);

            if ($notify && $wasUsable && $this->workspace->owner) {
                SendNotification::dispatch(
                    user: $this->workspace->owner,
                    type: Type::AccountDisconnected,
                    mailable: new AccountDisconnected($this),
                );
            }
        } finally {
            $lock->release();
        }
    }

    public function markAsConnected(): void
    {
        $this->update([
            'status' => Status::Connected,
            'error_message' => null,
            'disconnected_at' => null,
        ]);
    }

    public function hasPostingSchedule(): bool
    {
        return ($this->posting_schedule?->nextSlots(now(), $this->timezone, 1) ?? []) !== [];
    }

    public function isDisconnected(): bool
    {
        return $this->status === Status::Disconnected || $this->status === Status::TokenExpired;
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', Status::Connected);
    }

    public function scopeIncludedInAnalytics(Builder $query): Builder
    {
        return $query->whereIn('platform', SocialPlatform::analyticsValues());
    }
}
