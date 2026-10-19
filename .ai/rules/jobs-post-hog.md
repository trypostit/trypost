---
paths:
  - app/Listeners/StripeEventListener.php
  - app/Jobs/PostHog/TrackBilling.php
  - app/Jobs/PostHog/SyncAccountUsage.php
  - 'app/Listeners/Stripe*.php'
  - 'app/Jobs/PostHog/**'
---

# Jobs Post Hog

## Sync PostHog billing state after Cashier
Listen to Cashier's WebhookHandled event so local subscription state is persisted before PostHog jobs are dispatched. Account group properties are the current snapshot: subscription_status is canceled as soon as an ends_at exists (including grace period), while has_active_subscription remains true until access ends. Keep first_month_offer_ends_at event-derived and set it only on subscription creation so renewals cannot extend it.

## Stripe webhooks without a Cashier handler go through StripeBillingSignalListener
Cashier fires `WebhookHandled` only for types with a `handle*` method. `checkout.session.expired`, `invoice.payment_failed` and `invoice.upcoming` have none, so `StripeBillingSignalListener` listens to `WebhookReceived` for them.
It must filter on `HANDLED_TYPES` BEFORE its own `stripe_billing_signal:{id}` dedupe: `WebhookReceived` fires for every webhook, and deduping first would consume `StripeEventListener`'s `stripe_webhook_event:` key and silently drop subscription events.
New Cashier-handled types (`customer.subscription.*`, ...) stay in `StripeEventListener` (`WebhookHandled`).

## Lifecycle stage first sync is silent; post.published is claimed atomically
`accounts.lifecycle_stage` null means a pre-deploy account: the first sync writes properties only, no `lifecycle_stage.changed` / `activation.reached`, so workflows do not all fire at once. `CreateUser` seeds `signed_up`, so new accounts emit real transitions.
Stage definitions and thresholds live in `LifecycleStage` and the `ResolveLifecycleStage` constants (`ACTIVE_WITHIN_DAYS`, `AT_RISK_WITHIN_DAYS`); provisional, pending partner sign-off.
`post.published` fires once per post group: `TrackPostGroupPublished` claims it with an atomic `Cache::add("posthog:post_group_published:{group}")`. Never a read-then-decide guard, which races between the group's jobs.

## Tracking from domain code must never break the user's action
Domain code (actions, observers, controllers, middleware) only dispatches PostHog jobs, gated by `PostHogService::isEnabled()`. Building the properties and the dispatch run inside `rescue()` with a block closure (or a void method), never `rescue(fn () => X::dispatch())`: a returned `PendingDispatch` is pushed when it is destroyed, after `rescue()` has returned.
Run the tracking after the transaction when the action has one (`WorkspaceController::store`, `ActivateRepurpose`), so no tracking query runs inside it: a failed statement aborts a PostgreSQL transaction even when `rescue()` swallows the exception. Where the dispatch must sit inside a transaction (model observers such as `SocialAccountObserver::trackDisconnected`), use `->afterCommit()` so a rolled-back transaction never emits an event; that deferred queue push happens at commit, outside `rescue()`. Outside a transaction, no `afterCommit()`.
Stripe-driven jobs use `shouldTrack()` instead, like the existing billing jobs.
