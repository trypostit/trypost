<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Auth\SocialAuthProvider;
use App\Enums\Notification\Type as NotificationType;
use App\Enums\User\DefaultPostAction;
use App\Enums\User\Locale;
use App\Enums\User\Persona;
use App\Enums\User\PreviousSoftware;
use App\Enums\User\ReferralSource;
use App\Enums\User\Theme;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Traits\HasAccount;
use App\Models\Traits\HasMedia;
use App\Models\Traits\HasWorkspace;
use App\Models\Traits\SyncsStripeCustomer;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\RoutesNotifications;
use Illuminate\Support\Str;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasAccount, HasApiTokens, HasFactory, HasMedia, HasUuids, HasWorkspace, RoutesNotifications, SyncsStripeCustomer;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'github_id',
        'account_id',
        'current_workspace_id',
        'email_verified_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'gclid',
        'fbclid',
        'li_fat_id',
        'ttclid',
        'rdt_cid',
        'epik',
        'registration_ip',
        'persona',
        'goals',
        'previous_software',
        'referral_source',
        'locale',
        'timezone',
        'theme',
        'time_format',
        'week_starts_on',
        'default_post_action',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'theme' => Theme::DEFAULT->value,
        'time_format' => TimeFormat::DEFAULT->value,
        'week_starts_on' => WeekStart::DEFAULT->value,
        'default_post_action' => DefaultPostAction::DEFAULT->value,
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected $appends = [
        'has_photo',
        'photo_url',
    ];

    public function avatarMedia(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')->where('collection', 'avatar')->orderBy('order');
    }

    public function getHasPhotoAttribute(): bool
    {
        return $this->resolveAvatar() !== null;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->resolveAvatar()?->url;
    }

    private function resolveAvatar(): ?Media
    {
        return $this->relationLoaded('avatarMedia') ? $this->avatarMedia : $this->getFirstMedia('avatar');
    }

    /**
     * First whitespace-delimited token of the display name (empty when unset).
     */
    public function firstName(): string
    {
        return (string) Str::of($this->name ?? '')->trim()->before(' ');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'inbox_reorder_hint_seen_at' => 'datetime',
            'persona' => Persona::class,
            'goals' => 'array',
            'previous_software' => PreviousSoftware::class,
            'referral_source' => ReferralSource::class,
            'locale' => Locale::class,
            'theme' => Theme::class,
            'time_format' => TimeFormat::class,
            'week_starts_on' => WeekStart::class,
            'default_post_action' => DefaultPostAction::class,
        ];
    }

    public function preferredLocale(): string
    {
        return $this->locale->value;
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function mediaSourceConnections(): HasMany
    {
        return $this->hasMany(MediaSourceConnection::class);
    }

    public function wantsEmailFor(NotificationType $type): bool
    {
        $preference = $this->notificationPreference;

        return match ($type) {
            NotificationType::PostReady, NotificationType::MentionedInComment => false,
            NotificationType::PostPublished => $preference->post_published ?? true,
            NotificationType::PostFailed => $preference->post_failed ?? true,
            NotificationType::AccountDisconnected, NotificationType::PostAtRisk => $preference->account_disconnected ?? true,
            NotificationType::PostNoteAdded => $preference->post_note_added ?? true,
            NotificationType::Collaboration => $preference->collaboration ?? true,
            NotificationType::InboxActivity => $preference->inbox_activity ?? true,
        };
    }

    public function isConnectedTo(SocialAuthProvider $provider): bool
    {
        return (bool) $this->{"{$provider->value}_id"};
    }

    /**
     * Whether unlinking this provider still leaves the user a way to sign in:
     * a password or another connected provider.
     */
    public function canDisconnectFrom(SocialAuthProvider $provider): bool
    {
        if ($this->password) {
            return true;
        }

        return collect(SocialAuthProvider::cases())
            ->reject(fn (SocialAuthProvider $other): bool => $other === $provider)
            ->contains(fn (SocialAuthProvider $other): bool => $this->isConnectedTo($other));
    }
}
