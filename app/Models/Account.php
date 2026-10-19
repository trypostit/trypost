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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Stripe\Subscription as StripeSubscription;

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

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, Workspace::class);
    }

    public function socialAccounts(): HasManyThrough
    {
        return $this->hasManyThrough(SocialAccount::class, Workspace::class);
    }

    /**
     * The aggregates the lifecycle stage is resolved from: whether a channel is
     * connected, and the first and last posts TryPost published.
     */
    public function scopeWithLifecycleActivity(Builder $query): Builder
    {
        $publishedByTryPost = fn (Builder $posts): Builder => $posts->createdInTryPost()->publicationPublished();

        return $query
            ->withExists(['socialAccounts as has_connected_channel' => fn (Builder $channels): Builder => $channels->connected()])
            ->withMin(['posts as first_published_at' => $publishedByTryPost], 'published_at')
            ->withMax(['posts as last_published_at' => $publishedByTryPost], 'published_at')
            ->withCasts([
                'has_connected_channel' => 'boolean',
                'first_published_at' => 'datetime',
                'last_published_at' => 'datetime',
            ]);
    }

    /** Incomplete / incomplete_expired never became billable. */
    public function hasHadBillableSubscription(): bool
    {
        return $this->subscriptions->contains(fn (Subscription $subscription): bool => ! in_array($subscription->stripe_status, [
            StripeSubscription::STATUS_INCOMPLETE,
            StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
        ], true));
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

        $requiresCardForTrial = (bool) config('trypost.billing.require_card_for_trial', true);

        return $this->subscribed(self::SUBSCRIPTION_NAME)
            || (! $requiresCardForTrial && $this->isOnTrial());
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
        if (! (bool) config('trypost.billing.require_card_for_trial', true) && $this->onGenericTrial()) {
            return true;
        }

        return (bool) $this->subscription(self::SUBSCRIPTION_NAME)?->onTrial();
    }

    public function activeTrialEndsAt(): ?CarbonInterface
    {
        $subscription = $this->subscription(self::SUBSCRIPTION_NAME);

        if (! $subscription?->onTrial()) {
            if (! (bool) config('trypost.billing.require_card_for_trial', true) && $this->onGenericTrial()) {
                return $this->trial_ends_at;
            }

            return null;
        }

        return $subscription->trial_ends_at;
    }

    /**
     * Returns the displayable card for the billing UI. Falls back to the first
     * attached payment method when the customer has no `invoice_settings.default_payment_method`
     * (Stripe Checkout trials anchor the card to the subscription, not the customer).
     *
     * @return array{brand: string, last4: string, exp_month: int, exp_year: int}|null
     */
    public function displayablePaymentMethod(): ?array
    {
        $paymentMethod = $this->defaultPaymentMethod() ?? $this->paymentMethods()->first();
        $card = $paymentMethod?->card;

        if (! $card) {
            return null;
        }

        return [
            'brand' => $card->brand,
            'last4' => $card->last4,
            'exp_month' => $card->exp_month,
            'exp_year' => $card->exp_year,
        ];
    }
}
