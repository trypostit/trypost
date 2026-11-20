---
paths:
  - 'app/Listeners/Stripe*.php'
  - 'app/Jobs/PostHog/**'
---

# Jobs Post Hog

## Sync PostHog billing state after Cashier
Listen to Cashier's WebhookHandled event so local subscription state is persisted before PostHog jobs are dispatched. Account group properties are the current snapshot: subscription_status is canceled as soon as an ends_at exists (including grace period), while has_active_subscription remains true until access ends. Keep the group's offer event-derived and set it only on subscription creation so renewals cannot change it.

## Stripe webhooks without a Cashier handler go through StripeBillingSignalListener
Cashier fires `WebhookHandled` only for types with a `handle*` method. `checkout.session.expired`, `invoice.payment_failed` and `invoice.upcoming` have none, so `StripeBillingSignalListener` listens to `WebhookReceived` for them.
It must filter on its `JOBS` map (type => job, also the source of the extra `cashier.webhook.events`) BEFORE its own `stripe_billing_signal:{id}` dedupe: `WebhookReceived` fires for every webhook, and deduping first would consume `StripeEventListener`'s `stripe_webhook_event:` key and silently drop subscription events.
New Cashier-handled types (`customer.subscription.*`, ...) stay in `StripeEventListener` (`WebhookHandled`).

## Lifecycle stage first sync is silent; post.published is claimed atomically
`accounts.lifecycle_stage` null means a pre-deploy account: the first sync writes properties only, no `lifecycle_stage.changed` / `activation.reached`, so workflows do not all fire at once. `CreateUser` seeds `signed_up`, so new accounts emit real transitions.
Stage definitions and thresholds live in `LifecycleStage` and the `ResolveLifecycleStage` constants (`ACTIVE_WITHIN_DAYS`, `AT_RISK_WITHIN_DAYS`).
`churned` means the account was charged at least once: `accounts.first_paid_at`, written by `StripeEventListener` the first time a subscription webhook says `active` (backfilled from local subscriptions by its migration). Never decide it from the subscription dates: Cashier's `customer.subscription.deleted` handler runs `skipTrial()` and clears `trial_ends_at`, so a trial cancelled before its first charge looks paid afterwards.
`post.published` fires once per post group: `TrackPostPublished` claims it with an atomic `Cache::add("posthog:post_group_published:{group}")`. Never a read-then-decide guard, which races between the group's jobs.

## Tracking from domain code must never break the user's action
Domain code (actions, observers, controllers, middleware, listeners) only dispatches a PostHog job, inline where the action happens: `X::dispatchIf(PostHogService::isEnabled(), ...)` (`shouldTrack()` in the Stripe listeners), putting any other condition in the same `dispatchIf`. It passes ids and plain values; the job loads the models and builds the properties, so no tracking query runs in the request (`TrackRepurposeActivated`, `TrackWorkspaceLimitHit`). Outside the Stripe listeners (which catch everything themselves), wrap it in `rescue(function () use (...): void { X::dispatchIf(...); })` (`CreateApiKey`). Never `rescue(fn () => X::dispatchIf(...))`: a returned `PendingDispatch` is pushed when it is destroyed, after `rescue()` has returned. When the tracking needs more than that one dispatch (an early return, a claim, values read from the request), it goes in one private `track<EnumCase>` method of the same class, which the caller calls in one line (`LoadMcpWorkspace::trackToolCalled`).
Dispatch after the action's transaction, never inside it (`ActivateRepurpose`, `SocialController@disconnect`). A model observer may run inside a caller's transaction (`SocialAccountObserver::updated`): wrap it in `DB::afterCommit(function () use (...): void { rescue(function () use (...): void { ... }); })`. A rolled-back transaction then emits nothing, and the push runs after commit, inside `rescue()`. Never `->afterCommit()` on the dispatch: that push happens at commit, outside `rescue()`, and a failure there breaks the caller's commit.
A system command that saves state and then announces it (`SyncPostHogLifecycleStages`) does both per item in `rescue(function () { DB::transaction(function () { save; dispatch; }); })`, the dispatch without `afterCommit()`: a failed push rolls the save back so the next run retries it, and the loop goes on.

## Who an event is attributed to
The `distinct_id` is the user who acted, passed by the caller (`auth()->id()` in an action, `$request->user()`); a job falls back to the model's creator, then to the account owner, when it has none (`TrackRepurposeActivated`, `TrackSocialAccountConnected` for Telegram). System events with nobody acting go to the owner (Stripe jobs, `TrackSocialAccountConnectionLost`, lifecycle). Posts are the exception: always their author, never a fallback.
`subscription.cancelled` is sent once per Stripe subscription (`posthog:subscription_cancelled:{id}`): a dunning that ends in `unpaid` clears `plan_id` before the delete arrives, so the delete is tracked when the account still has a plan or has paid (`first_paid_at`), never for an abandoned checkout.

## Imported network posts never feed PostHog
Only posts written in TryPost by a user count. Every post query behind a PostHog event or property filters `createdInTryPost()` (`SyncAccountPublishingActivity`, `Account::scopeWithLifecycleActivity`, `TrackPostPublished` `is_first`, `HasUsage::cachedPostCount`), and the post jobs (`TrackPost`, `TrackPostPublished`, `TrackPostFailed`) load the post with `->createdInTryPost()->whereNotNull('user_id')`, so a post imported from the network or without an author is never found, with no fallback to the owner.

## Naming tracking code
A dedicated job exists only when it has its own logic (it loads models or decides something); otherwise dispatch `CaptureAccountEvent` with the enum case. Dedicated jobs are named `Track<Subject><EnumCase>` (`TrackPostPublished`, `TrackWorkspaceLimitHit`). When one event has two causes, each cause gets its job, named after it: `social_account.disconnected` is `TrackSocialAccountRemoved` (a member removed it) and `TrackSocialAccountConnectionLost` (the system lost the connection). A job that picks between two events is named after the decision (`TrackPlanChanged`, `TrackLifecycleChange`).
