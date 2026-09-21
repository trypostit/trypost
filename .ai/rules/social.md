---
paths:
  - app/Services/Social/GoogleBusinessPublisher.php
  - app/Support/Social/GoogleBusinessDerivativeCleaner.php
  - app/Actions/Post/DeletePost.php
  - app/Actions/Workspace/PurgeWorkspace.php
---

# Social

## GBP JPEG must outlive PROCESSING
Google fetches Local Post sourceUrl after create while LocalPostState is Processing/Scheduled/Unspecified. Keep the JPEG derivative on disk until reconcile settle() or RecoverStuckPosts fails the target. Deleting in publish() finally races PHOTO_FETCH_FAILED. Live/Rejected on the create response may prune immediately. Path is deterministic: google-business-derivatives/{postPlatformId}.jpg. Wire values live on App\Enums\GoogleBusiness\LocalPostState — never compare raw PROCESSING/SCHEDULED strings. RecoverStuckPosts must prune the JPEG on the 1h Publishing/Pending/Retrying timeout (the worker can die after writing the file and before PendingReview) and again on the 24h review ceiling. DeletePost and PurgeWorkspace must prune every Google Business PostPlatform JPEG before the row disappears — a user delete during pending_review or a workspace wipe would otherwise leak the file, and a DB cascade on post_platforms does not fire Eloquent observers.
