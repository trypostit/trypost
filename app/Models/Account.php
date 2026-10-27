<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Account\LifecycleStage;
use App\Models\Traits\BillsOwnerAsStripeCustomer;
use App\Models\Traits\HasUsage;
use Carbon\CarbonInterface;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BillsOwnerAsStripeCustomer, HasFactory, HasUsage, HasUuids;

    public const SUBSCRIPTION_NAME = 'default';

    public static function postsCountCacheKey(string $accountId): string
    {
        return "account:{$accountId}:posts_count";
    }

    protected $fillable = [
        'owner_id',
        'name',
        'plan_id',
        'trial_ends_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'onboarding_dismissed_at' => 'datetime',
        'onboarding_skipped_steps' => 'array',
        'lifecycle_stage' => LifecycleStage::class,
        'lifecycle_stage_changed_at' => 'datetime',
        'activated_at' => 'datetime',
        'first_paid_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class)->withPivot('claimed_at', 'redeemed_at');
    }

    /**
     * The offer a checkout uses: the most recently claimed one the account may
     * still use. Without one it pays the full price. Subscribing from billing
     * leaves trials out, since it charges at once.
     */
    public function currentOffer(bool $withTrial = true): ?Offer
    {
        return $this->offers()
            ->availableTo($this)
            ->unless($withTrial, fn (Builder $query): Builder => $query->withoutTrial())
            ->orderByPivot('claimed_at', 'desc')
            ->first();
    }

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, Workspace::class);
    }

    public function socialAccounts(): HasManyThrough
    {
        return $this->hasManyThrough(SocialAccount::class, Workspace::class);
    }

    /**
     * The aggregates the lifecycle stage is resolved from: whether any channel
     * exists, whatever its status, and the first and last posts TryPost published.
     */
    public function scopeWithLifecycleActivity(Builder $query): Builder
    {
        $publishedByTryPost = fn (Builder $posts): Builder => $posts->createdInTryPost()->publicationPublished();

        return $query
            ->withExists('socialAccounts as has_social_account')
            ->withMin(['posts as first_published_at' => $publishedByTryPost], 'published_at')
            ->withMax(['posts as last_published_at' => $publishedByTryPost], 'published_at')
            ->withCasts([
                'has_social_account' => 'boolean',
                'first_published_at' => 'datetime',
                'last_published_at' => 'datetime',
            ]);
    }

    /**
     * Never billed and never redeemed an offer: a no-card trial is a redeemed
     * offer, so it counts as the first subscription and trials do not stack.
     * Cashier clears `trial_ends_at` on any new subscription, so the
     * redemption is the record that lasts.
     */
    public function isFirstTimeSubscriber(): bool
    {
        return ! $this->hasHadBillableSubscription()
            && $this->offers()->wherePivotNotNull('redeemed_at')->doesntExist();
    }

    public function hasHadBillableSubscription(): bool
    {
        return $this->subscriptions->contains(fn (Subscription $subscription): bool => $subscription->becameBillable());
    }

    /**
     * Charged at least once. Cashier clears `trial_ends_at` when a subscription
     * is deleted, so a trial cancelled before its first charge cannot be told
     * apart from a paid one afterwards; `first_paid_at` keeps that fact.
     */
    public function hasPaidSubscription(): bool
    {
        return filled($this->first_paid_at);
    }

    public function hasActiveSubscription(): bool
    {
        if (config('trypost.self_hosted')) {
            return true;
        }

        return $this->subscribed(self::SUBSCRIPTION_NAME);
    }

    public function subscriptionStatus(): string
    {
        $subscription = $this->subscription(self::SUBSCRIPTION_NAME);

        if (! $subscription) {
            return 'none';
        }

        return $subscription->canceled() ? 'canceled' : $subscription->stripe_status;
    }

    public function hasAppAccess(): bool
    {
        if (config('trypost.self_hosted')) {
            return true;
        }

        return $this->subscribed(self::SUBSCRIPTION_NAME) || $this->onGenericTrial();
    }

    public function workspaceLimit(): ?int
    {
        if (config('trypost.self_hosted')) {
            return null;
        }

        if ($this->plan === null) {
            return 1;
        }

        return $this->plan->workspace_limit;
    }

    public function canCreateWorkspace(): bool
    {
        if (config('trypost.self_hosted')) {
            return true;
        }

        $limit = $this->workspaceLimit();

        if ($limit === null) {
            return true;
        }

        return $this->workspaces()->count() < $limit;
    }

    public function isPastDue(): bool
    {
        if (config('trypost.self_hosted')) {
            return false;
        }

        return (bool) $this->subscription(self::SUBSCRIPTION_NAME)?->pastDue();
    }

    public function isOnTrial(): bool
    {
        return $this->onGenericTrial() || (bool) $this->subscription(self::SUBSCRIPTION_NAME)?->onTrial();
    }

    public function activeTrialEndsAt(): ?CarbonInterface
    {
        $subscription = $this->subscription(self::SUBSCRIPTION_NAME);

        if ($subscription?->onTrial()) {
            return $subscription->trial_ends_at;
        }

        return $this->onGenericTrial() ? $this->trial_ends_at : null;
    }
}
