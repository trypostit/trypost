<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Notification\Type as NotificationType;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Models\Traits\HasMedia;
use App\Models\Traits\HasWorkspace;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasMedia, HasUuids, HasWorkspace, Notifiable;

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
        'registration_ip',
        'persona',
        'goals',
        'referral_source',
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

    public function getHasPhotoAttribute(): bool
    {
        return $this->getFirstMedia('avatar') !== null;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('avatar');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'persona' => Persona::class,
            'goals' => 'array',
            'referral_source' => ReferralSource::class,
        ];
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isAccountOwner(): bool
    {
        return $this->id === $this->account?->owner_id;
    }

    public function wantsEmailFor(NotificationType $type): bool
    {
        $preference = $this->notificationPreference;

        if (! $preference) {
            return true;
        }

        return match ($type) {
            NotificationType::PostPublished => $preference->post_published,
            NotificationType::PostFailed, NotificationType::PostPartiallyPublished => $preference->post_failed,
            NotificationType::AccountDisconnected => $preference->account_disconnected,
            NotificationType::MentionedInComment => $preference->mentioned_in_comment ?? true,
            default => true,
        };
    }
}
