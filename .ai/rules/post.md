---
paths:
  - app/Actions/Post/FinalizePostPublication.php
  - app/Jobs/PublishPost.php
  - app/Actions/Post/UpdatePost.php
  - 'app/Actions/Post/**'
---

# Post

## FinalizePostPublication is the only post settler
handle() takes the Post, not a dummy PostPlatform. Every path that can finish the last enabled target must call it: PublishToSocialPlatform, ReconcileGoogleBusinessPost, RecoverStuckPosts, PublishPost::failed, and AbandonGoogleBusinessReview (disconnect / disable during pending_review). No enabled targets on a draft or scheduled post is a no-op — do not mark the post published. A Publishing post with no enabled targets is abandoned in-flight: mark it Failed so it does not sit non-editable forever. Do not mark the post Published / PartiallyPublished / Failed by hand outside Finalize. The one exception is the one-off release split (posts:split-legacy-active), which derives already-settled statuses quietly, with no events or notifications.

## Finalize is idempotent once the post is settled
handle() lockForUpdates the post and returns without notifying when status is already Published, PartiallyPublished, or Failed (Status::isSettled()). RecoverStuckPosts and ReconcileGoogleBusinessPost can both finish the last target at the 24h ceiling; the second call must not send a second email. Dispatch SendNotification only after the transaction commits.

## Unchecked destination abandons pending_review with target_disabled
Abandoning a GBP pending_review because the post destination was unchecked uses posts.errors.target_disabled.

## One enabled destination per new post
New draft/scheduled posts are independent per social account: use CreatePosts/CreateChannelPost, with one enabled PostPlatform per Post; do not revive grouped CreatePost/SyncPostPlatforms writes. Editing uses UpdatePost and may change content type within the same account, never the social account. posts:split-legacy-active (release:trypost-2) splits editable and settled multi-target posts, moving each enabled target to its own post with the status of that target; only in-flight aggregates and settled posts with an unfinished target stay grouped. A split original may retain disabled placeholder targets, so count enabled targets when deciding if it is editable.

## No per-destination validation inside a repurpose batch
ProcessRepurposeItem creates all posts of one run in a single all-or-nothing transaction (CreatePosts batch + SyncOwnedMedia). Never add per-destination/per-platform validation inside CreateChannelPost or SyncOwnedMedia: one bad destination would roll back the whole run, and repurpose items are never retried. Destination health stays a publish-time failure (AGENTS.md "Repurpose account health"); platform rules belong in the FormRequests/CreatePosts validation for user-driven flows.

## ImportExternalPosts is the only exception to Finalize
App\Actions\Post\ImportExternalPosts is the single path allowed to create Published posts (origin network) without FinalizePostPublication. Those posts were already published on the network, not by TryPost, so they are written quietly under Post::withoutEvents: no notification, webhook, PostHog event or job. Every post TryPost publishes itself still settles through Finalize.

## PostApproval is the only approval gate
Every post write that can schedule, queue or publish passes the acting user to CreatePosts / UpdatePost so App\Support\PostApproval can store Status::PendingApproval. Do not gate in controllers, API or MCP tools, and do not pass an actor from system callers (ScheduleNextOccurrence), or approved recurring series start asking again. A pending queue request is never placed in the queue; ApprovePost replays it through UpdatePost as the approver. Appending media to a post (MCP/API attach) goes through AppendPostMedia, never Post::appendMedia directly from an entry point, so an approved post edited by a requester returns to approval. Approve, reject and any UpdatePost of a pending post run under PostApproval::whilePending (lock post-approval:{id}, re-entrant).
