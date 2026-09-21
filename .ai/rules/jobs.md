---
paths:
  - app/Jobs/ReconcileGoogleBusinessPost.php
  - app/Console/Commands/RecoverStuckPosts.php
---

# Jobs

## GBP reconcile must settle permanent API errors
ReconcileGoogleBusinessPost must not rethrow GoogleBusinessPublishException. Permanent categories (NOT_FOUND, PERMISSION_DENIED, INVALID_ARGUMENT, Unknown) reject the target immediately and call FinalizePostPublication. Only ServerError and RateLimit defer until REVIEW_CEILING_HOURS. Throwing leaves last_reconciled_at stale, the 5-minute sweep re-dispatches, RecoverStuckPosts treats PendingReview as still-active, and the post sticks forever. failed() is the safety net if the worker dies mid-review. RecoverStuckPosts may only fail PendingReview after the same 24h ceiling (submitted_at), never the 1h publishing timeout.
