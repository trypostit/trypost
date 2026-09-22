---
paths:
  - 'app/Listeners/StripeEventListener.php'
  - 'app/Jobs/PostHog/TrackBilling.php'
  - 'app/Jobs/PostHog/SyncAccountUsage.php'
---

# Jobs Post Hog

## Sync PostHog billing state after Cashier
Listen to Cashier's WebhookHandled event so local subscription state is persisted before PostHog jobs are dispatched. Account group properties are the current snapshot: subscription_status is canceled as soon as an ends_at exists (including grace period), while has_active_subscription remains true until access ends. Keep first_month_offer_ends_at event-derived and set it only on subscription creation so renewals cannot extend it.
