---
paths:
  - app/Jobs/ReconcileGoogleBusinessPost.php
  - app/Console/Commands/RecoverStuckPosts.php
---

# Jobs

## GBP reconcile must settle permanent API errors
ReconcileGoogleBusinessPost must not rethrow GoogleBusinessPublishException. Permanent categories (NOT_FOUND, PERMISSION_DENIED, INVALID_ARGUMENT, Unknown) reject the target immediately and call FinalizePostPublication. Only ServerError, RateLimit, and ConnectionException defer until REVIEW_CEILING_HOURS. Throwing leaves last_reconciled_at stale, the 5-minute sweep re-dispatches, RecoverStuckPosts treats PendingReview as still-active, and the post sticks forever. failed() is the safety net if the worker dies mid-review — it must deferOrGiveUp (respect the 24h ceiling), never giveUp immediately. Job $timeout must exceed HasSocialHttpClient's 120s HTTP timeout. RecoverStuckPosts may only fail PendingReview after the same 24h ceiling (submitted_at), never the 1h publishing timeout. JPEG derivatives stay on disk while state is PROCESSING/SCHEDULED so Google can fetch sourceUrl; settle() and RecoverStuckPosts' expired-review path prune them.
