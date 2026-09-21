---
paths:
  - app/Jobs/ReconcileGoogleBusinessPost.php
  - app/Console/Commands/RecoverStuckPosts.php
---

# Jobs

## GBP reconcile must settle permanent API errors
ReconcileGoogleBusinessPost must not rethrow GoogleBusinessPublishException. Permanent categories (NOT_FOUND, PERMISSION_DENIED, INVALID_ARGUMENT, Unknown) reject the target immediately and call FinalizePostPublication. Only ServerError, RateLimit, and ConnectionException defer until REVIEW_CEILING_HOURS. Throwing leaves last_reconciled_at stale, the 5-minute sweep re-dispatches, RecoverStuckPosts treats PendingReview as still-active, and the post sticks forever. failed() is the safety net if the worker dies mid-review — it must deferOrGiveUp (respect the 24h ceiling), never giveUp immediately. Job $timeout must exceed HasSocialHttpClient's 120s HTTP timeout. RecoverStuckPosts may only fail PendingReview after the same 24h ceiling timed from submitted_at — never created_at (a scheduled draft can be days old before it enters review) and never the 1h publishing timeout. Reconcile must refresh-and-retry on TokenExpiredException the same way PublishToSocialPlatform does; if refresh also dies, mark the social account token-expired before deferring. A 401 must not sit in review for 24h while a refresh would have settled it. JPEG derivatives stay on disk while LocalPostState is Processing/Scheduled so Google can fetch sourceUrl; settle() and RecoverStuckPosts' expired-review path prune them. When RecoverStuckPosts finishes a post (no still-active targets), call FinalizePostPublication so the owner is notified — do not mark the post Failed/Published by hand. Never compare raw PROCESSING/SCHEDULED/LIVE strings — use App\Enums\GoogleBusiness\LocalPostState.
