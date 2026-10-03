---
paths:
  - app/Services/Social/GoogleBusinessPublisher.php
  - app/Support/Social/GoogleBusinessDerivativeCleaner.php
  - app/Actions/Post/DeletePost.php
  - app/Actions/Post/UpdatePost.php
  - app/Support/Social/AbandonGoogleBusinessReview.php
  - app/Actions/Workspace/PurgeWorkspace.php
  - app/Http/Controllers/Auth/SocialController.php
  - app/Support/Social/ThreadProgress.php
  - app/Support/ThreadReplies.php
  - app/Services/Social/Concerns/PublishesThreads.php
---

# Social

## GBP JPEG must outlive PROCESSING
Google fetches Local Post sourceUrl after create while LocalPostState is Processing/Scheduled/Unspecified. Keep the JPEG derivative on disk until reconcile settle() or RecoverStuckPosts fails the target. Deleting in publish() finally races PHOTO_FETCH_FAILED. Live/Rejected on the create response may prune immediately. Path is deterministic: google-business-derivatives/{postPlatformId}.jpg. Wire values live on App\Enums\GoogleBusiness\LocalPostState — never compare raw PROCESSING/SCHEDULED strings. RecoverStuckPosts must prune the JPEG on the 1h Publishing/Pending/Retrying timeout (the worker can die after writing the file and before PendingReview), on a disabled GBP target (reconcile and the 24h ceiling skip `enabled=false`), and again on the 24h review ceiling. UpdatePost mass-updates `enabled=false` (observers never fire): abandon any GBP still in pending_review that is not in the kept set, then prune leftover JPEGs after commit. Disconnect deletes the channel's posts (`DeleteChannelPosts`) before the account delete and prunes their JPEGs, so no pending_review row is left with a null account. DeletePost and PurgeWorkspace must prune every Google Business PostPlatform JPEG before the row disappears — a user delete during pending_review or a workspace wipe would otherwise leak the file, and a DB cascade on post_platforms does not fire Eloquent observers.

## Thread checkpoints resume, never re-post
Bluesky/Mastodon thread segments already live are checkpointed in post_platforms.error_context.thread_progress (ThreadProgress) after each segment, so any retry (incl. posts:retry, which keeps them) resumes from the next segment. A resume keeps the stored root hash: the root is the target's identity even if the same text would now hash differently. Never clear thread_progress on retry and never re-post a checkpointed segment. thread_reply_ids lists the reply ids so ImportExternalPosts skips TryPost's own replies.
