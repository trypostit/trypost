---
paths:
  - 'app/Listeners/Stripe*.php'
  - 'app/Jobs/PostHog/**'
---

# Jobs Post Hog

## Sync PostHog billing state after Cashier
Listen to Cashier's WebhookHandled event so local subscription state is persisted before PostHog jobs are dispatched. Account group properties are the current snapshot: subscription_status is canceled as soon as an ends_at exists (including grace period), while has_active_subscription remains true until access ends. Keep first_month_offer_ends_at event-derived and set it only on subscription creation so renewals cannot extend it.

## Stripe webhooks without a Cashier handler go through StripeBillingSignalListener
Cashier fires `WebhookHandled` only for types with a `handle*` method. `checkout.session.expired`, `invoice.payment_failed` and `invoice.upcoming` have none, so `StripeBillingSignalListener` listens to `WebhookReceived` for them.
It must filter on its `JOBS` map (type => job, also the source of the extra `cashier.webhook.events`) BEFORE its own `stripe_billing_signal:{id}` dedupe: `WebhookReceived` fires for every webhook, and deduping first would consume `StripeEventListener`'s `stripe_webhook_event:` key and silently drop subscription events.
New Cashier-handled types (`customer.subscription.*`, ...) stay in `StripeEventListener` (`WebhookHandled`).

## Lifecycle stage first sync is silent; post.published is claimed atomically
`accounts.lifecycle_stage` null means a pre-deploy account: the first sync writes properties only, no `lifecycle_stage.changed` / `activation.reached`, so workflows do not all fire at once. `CreateUser` seeds `signed_up`, so new accounts emit real transitions.
Stage definitions and thresholds live in `LifecycleStage` and the `ResolveLifecycleStage` constants (`ACTIVE_WITHIN_DAYS`, `AT_RISK_WITHIN_DAYS`).
`post.published` fires once per post group: `TrackPostPublished` claims it with an atomic `Cache::add("posthog:post_group_published:{group}")`. Never a read-then-decide guard, which races between the group's jobs.

## Tracking from domain code must never break the user's action
Domain code (actions, observers, controllers, middleware) only dispatches PostHog jobs, from one private `track*` method that the caller calls directly. That method returns early unless `PostHogService::isEnabled()`, then builds the properties and dispatches inside `rescue(function () use (...): void { ... })` (`PostObserver::syncPublicationAnalytics`). Never `rescue(fn () => X::dispatch())`: a returned `PendingDispatch` is pushed when it is destroyed, after `rescue()` has returned.
Run the tracking after the transaction when the action has one (`ActivateRepurpose`), so no tracking query runs inside it: a failed statement aborts a PostgreSQL transaction even when `rescue()` swallows the exception. Tracking reads what it needs from a copy (`$model->withoutRelations()->load(...)`), never by loading relations on the model the action returns: that changes the API and MCP responses.
A model observer may run inside a caller's transaction (`SocialAccountObserver::trackDisconnected`): wrap the whole tracking in `DB::afterCommit(function () use (...): void { rescue(function () use (...): void { ... }); })`. A rolled-back transaction then emits nothing, and the queries and the push run after commit, inside `rescue()`. Never `->afterCommit()` on the dispatch: that push happens at commit, outside `rescue()`, and a failure there breaks the caller's commit.
A system command that saves state and then announces it (`SyncPostHogLifecycleStages`) does both per item in `rescue(function () { DB::transaction(function () { save; dispatch; }); })`, the dispatch without `afterCommit()`: a failed push rolls the save back so the next run retries it, and the loop goes on.
Stripe-driven jobs use `shouldTrack()` instead, like the existing billing jobs.

## Imported network posts never feed PostHog
Only posts written in TryPost by a user count. Every post query behind a PostHog event or property filters `createdInTryPost()` (`SyncAccountPublishingActivity`, `Account::scopeWithLifecycleActivity`, `TrackPostPublished` `is_first`, `HasUsage::cachedPostCount`), and the post jobs (`TrackPost`, `TrackPostPublished`, `TrackPostFailed`) return early for a post imported from the network or without an author (`user_id` null), with no fallback to the owner.

## Naming tracking code
A private method that dispatches PostHog tracking is named `track` + the event's enum case (`WorkspaceEvent::MemberInvited` => `trackMemberInvited`). A dedicated job exists only when it has its own logic; otherwise dispatch `CaptureAccountEvent` with the enum case. Dedicated jobs are named `Track<Subject><EnumCase>` (`TrackPostPublished`). A method or job that picks between two events is named after the decision (`trackSubscriptionStart`, `trackTrialConversion`, `TrackPlanChanged`, `TrackLifecycleChange`).
