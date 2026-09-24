<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasUsage;
use Carbon\CarbonInterface;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use Billable, HasFactory, HasUsage, HasUuids;

    public const SUBSCRIPTION_NAME = 'default';

    public static function postsCountCacheKey(string $accountId): string
    {
        return "account:{$accountId}:posts_count";
    }

    protected $fillable = [
        'owner_id',
        'name',
        'billing_email',
        'plan_id',
        'trial_ends_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'onboarding_dismissed_at' => 'datetime',
        'onboarding_skipped_steps' => 'array',
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

    public function hasActiveSubscription(): bool
    {
        if (config('trypost.self_hosted')) {
            return true;
        }

        return $this->subscribed(self::SUBSCRIPTION_NAME);
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

    public function stripeEmail(): string
    {
        return $this->billing_email ?? $this->owner?->email ?? '';
    }

    public function stripeName(): string
    {
        return $this->name;
    }
}
