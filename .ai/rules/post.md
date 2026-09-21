---
paths:
  - app/Actions/Post/FinalizePostPublication.php
  - app/Jobs/PublishPost.php
---

# Post

## FinalizePostPublication is the only post settler
handle() takes the Post, not a dummy PostPlatform. Every path that can finish the last enabled target must call it: PublishToSocialPlatform, ReconcileGoogleBusinessPost, RecoverStuckPosts, and PublishPost::failed. No enabled targets is a no-op — do not mark the post published. Do not mark the post Published / PartiallyPublished / Failed by hand — RecoverStuckPosts only sweeps Publishing posts, so a handmade Failed leaves pending targets stuck forever and skips the owner notice.

## In-app publish notice uses owner locale
SendNotification title/body are stored already-resolved. Resolve them through lang/*/notifications.php (post_published / post_failed) with $owner->preferredLocale() — the worker locale is English. Mailables stay untranslated at dispatch; Mail::to($owner) applies HasLocalePreference. Do not hardcode English title/body here.

## Finalize is idempotent once the post is settled
handle() lockForUpdates the post and returns without notifying when status is already Published, PartiallyPublished, or Failed (Status::isSettled()). RecoverStuckPosts and ReconcileGoogleBusinessPost can both finish the last target at the 24h ceiling; the second call must not send a second email or toast. Dispatch SendNotification only after the transaction commits.
