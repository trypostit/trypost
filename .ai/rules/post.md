---
paths:
  - app/Actions/Post/FinalizePostPublication.php
  - app/Jobs/PublishPost.php
  - app/Actions/Post/UpdatePost.php
---

# Post

## FinalizePostPublication is the only post settler
handle() takes the Post, not a dummy PostPlatform. Every path that can finish the last enabled target must call it: PublishToSocialPlatform, ReconcileGoogleBusinessPost, RecoverStuckPosts, PublishPost::failed, and AbandonGoogleBusinessReview (disconnect / disable during pending_review). No enabled targets on a draft or scheduled post is a no-op — do not mark the post published. A Publishing post with no enabled targets is abandoned in-flight: mark it Failed so it does not sit non-editable forever. Do not mark the post Published / PartiallyPublished / Failed by hand outside Finalize.

## In-app publish notice uses owner locale
SendNotification title/body are stored already-resolved. Resolve them through lang/*/notifications.php (post_published / post_failed) with $owner->preferredLocale() — the worker locale is English. Mailables stay untranslated at dispatch; Mail::to($owner) applies HasLocalePreference. Do not hardcode English title/body here.

## Finalize is idempotent once the post is settled
handle() lockForUpdates the post and returns without notifying when status is already Published, PartiallyPublished, or Failed (Status::isSettled()). RecoverStuckPosts and ReconcileGoogleBusinessPost can both finish the last target at the 24h ceiling; the second call must not send a second email or toast. Dispatch SendNotification only after the transaction commits.

## Target disabled is not account inactive
Abandoning a GBP pending_review because the post destination was unchecked uses posts.errors.target_disabled. posts.errors.account_inactive stays for PublishToSocialPlatform when social_accounts.is_active is false. Do not reuse the account copy on a switched-off target.
