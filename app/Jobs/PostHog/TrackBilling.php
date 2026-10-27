<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\BillingEvent;
use App\Models\Account;
use App\Services\PostHogService;
use App\Support\Billing\StripeValue;
use App\Support\StripeSubscriptionConversion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $accountId,
        public BillingEvent $event,
        public array $payload,
        public ?string $previousPlan = null,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::shouldTrack()) {
            return;
        }

        $account = Account::with('plan')->find($this->accountId);

        if (! $account || ! $account->owner_id) {
            return;
        }

        $postHog->capture(
            (string) $account->owner_id,
            $this->event->value,
            [
                'stripe_status' => data_get($this->payload, 'data.object.status'),
                'plan_slug' => $account->plan?->slug->value,
                'previous_plan' => $this->previousPlan,
                ...$this->cancellationProperties(),
                ...$this->cancelReasonProperties(),
            ],
            $account,
        );

        $postHog->groupIdentify('account', (string) $account->id, [
            'subscription_status' => $account->subscriptionStatus(),
            'has_active_subscription' => $account->hasActiveSubscription(),
            ...$this->currentCancellationProperties($account),
            ...$this->offerProperties(),
        ]);

        SyncUser::dispatch((string) $account->owner_id);
    }

    /**
     * @return array{cancel_at_period_end: bool, cancel_at: string|null}
     */
    private function cancellationProperties(): array
    {
        return [
            'cancel_at_period_end' => (bool) data_get($this->payload, 'data.object.cancel_at_period_end', false),
            'cancel_at' => StripeValue::timestamp(data_get($this->payload, 'data.object.cancel_at'))?->toIso8601String(),
        ];
    }

    /**
     * @return array{cancel_at_period_end: bool, cancel_at: string|null}
     */
    private function currentCancellationProperties(Account $account): array
    {
        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);
        $onGracePeriod = (bool) $subscription?->onGracePeriod();

        return [
            'cancel_at_period_end' => $onGracePeriod,
            'cancel_at' => $onGracePeriod ? $subscription->ends_at->toIso8601String() : null,
        ];
    }

    /**
     * @return array{}|array{cancel_reason: string|null, cancel_feedback: string|null}
     */
    private function cancelReasonProperties(): array
    {
        if ($this->event !== BillingEvent::Cancelled) {
            return [];
        }

        return [
            'cancel_reason' => data_get($this->payload, 'data.object.cancellation_details.reason'),
            'cancel_feedback' => data_get($this->payload, 'data.object.cancellation_details.feedback'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offerProperties(): array
    {
        if ($this->event !== BillingEvent::Created) {
            return [];
        }

        return [
            'offer_slug' => StripeSubscriptionConversion::offerSlug($this->payload),
        ];
    }
}
